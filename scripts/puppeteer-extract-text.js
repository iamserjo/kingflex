#!/usr/bin/env node

/**
 * Script to render a page and extract cleaned HTML with semantic structure.
 * Keeps important tags (h1-h6, p, a, img, etc.) and useful attributes (alt, title, href).
 * Removes junk attributes, empty tags, and non-content elements.
 * 
 * Usage: node puppeteer-extract-text.js <url> [--timeout=30000] [--wait-for=networkidle]
 * 
 * Options:
 *   --timeout=<ms>       Page load timeout in milliseconds (default: 30000)
 *   --wait-for=<event>   Wait until event: load, domcontentloaded, networkidle, commit
 *   --user-agent=<ua>    Custom user agent string
 *   --json               Output as JSON with metadata
 */

import { chromium } from 'playwright';

const args = process.argv.slice(2);

if (args.length === 0 || args[0] === '--help' || args[0] === '-h') {
    console.log(`
Usage: node puppeteer-extract-text.js <url> [options]

Options:
  --timeout=<ms>       Page load timeout in milliseconds (default: 30000)
  --wait-for=<event>   Wait until event: load, domcontentloaded, networkidle, commit
  --user-agent=<ua>    Custom user agent string
  --json               Output as JSON with metadata
  --help, -h           Show this help message

Examples:
  node puppeteer-extract-text.js https://example.com
  node puppeteer-extract-text.js https://example.com --timeout=60000 --json
  node puppeteer-extract-text.js https://example.com --wait-for=networkidle
`);
    process.exit(0);
}

// Parse arguments
const url = args.find(arg => !arg.startsWith('--'));
const getOption = (name, defaultValue) => {
    const arg = args.find(a => a.startsWith(`--${name}=`));
    return arg ? arg.split('=')[1] : defaultValue;
};
const hasFlag = (name) => args.includes(`--${name}`);

if (!url) {
    console.error('Error: URL is required');
    process.exit(1);
}

const timeout = parseInt(getOption('timeout', '30000'), 10);
const waitUntil = getOption('wait-for', 'networkidle');
const userAgent = getOption('user-agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
const outputJson = hasFlag('json');

async function extractText() {
    let browser = null;
    
    try {
        // Launch browser with appropriate flags for Docker/headless environment
        browser = await chromium.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                '--disable-accelerated-2d-canvas',
                '--no-first-run',
                '--disable-gpu',
            ],
        });

        const context = await browser.newContext({
            userAgent: userAgent,
            viewport: { width: 1920, height: 1080 },
        });

        const page = await context.newPage();

        // Navigate to the URL
        const startTime = Date.now();
        const response = await page.goto(url, {
            waitUntil: waitUntil,
            timeout: timeout,
        });
        const loadTime = Date.now() - startTime;

        // Extract page title
        const title = await page.title();

        // Extract meta description
        const metaDescription = await page.$eval(
            'meta[name="description"]',
            el => el.getAttribute('content')
        ).catch(() => null);

        // Extract meta keywords
        const metaKeywords = await page.$eval(
            'meta[name="keywords"]',
            el => el.getAttribute('content')
        ).catch(() => null);

        // Extract raw HTML
        const rawHtml = await page.content();

        // Extract all links from the page
        const extractedUrls = await page.evaluate(() => {
            const links = Array.from(document.querySelectorAll('a[href]'));
            const urls = [];
            const seen = new Set();
            
            for (const link of links) {
                try {
                    const href = link.getAttribute('href');
                    if (!href) continue;
                    
                    // Skip javascript:, mailto:, tel:, # anchors
                    if (href.startsWith('javascript:') || 
                        href.startsWith('mailto:') || 
                        href.startsWith('tel:') ||
                        href === '#' ||
                        href.startsWith('#')) {
                        continue;
                    }
                    
                    // Resolve relative URLs
                    const absoluteUrl = new URL(href, window.location.href).href;
                    
                    // Skip if already seen
                    if (seen.has(absoluteUrl)) continue;
                    seen.add(absoluteUrl);
                    
                    urls.push(absoluteUrl);
                } catch (e) {
                    // Skip invalid URLs
                }
            }
            
            return urls;
        });

        // Extract cleaned HTML with semantic structure
        const cleanedHtml = await page.evaluate(() => {
            // Tags to completely remove (including their content)
            const REMOVE_TAGS = new Set([
                'SCRIPT', 'STYLE', 'NOSCRIPT', 'IFRAME', 'SVG', 'PATH', 
                'TEMPLATE', 'CANVAS', 'AUDIO', 'VIDEO', 'SOURCE', 'TRACK',
                'EMBED', 'OBJECT', 'PARAM', 'MAP', 'AREA',
                'FOOTER'  // Footer usually contains navigation/legal info, not main content
            ]);

            // Tags that carry semantic meaning and should be preserved
            const SEMANTIC_TAGS = new Set([
                'H1', 'H2', 'H3', 'H4', 'H5', 'H6',  // Headings - importance
                'P', 'BLOCKQUOTE', 'PRE', 'CODE',    // Text blocks
                'A',                                  // Links - href is valuable
                'IMG',                                // Images - alt is valuable
                'UL', 'OL', 'LI',                    // Lists
                'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', // Tables
                'DL', 'DT', 'DD',                    // Definition lists
                'STRONG', 'B', 'EM', 'I', 'MARK',    // Emphasis
                'ARTICLE', 'SECTION', 'HEADER', 'MAIN', 'NAV', 'ASIDE',
                'FIGURE', 'FIGCAPTION',
                'FORM', 'INPUT', 'BUTTON', 'SELECT', 'OPTION', 'TEXTAREA', 'LABEL',
                'SPAN', 'DIV',                       // Generic containers (will be unwrapped if empty)
                'BR', 'HR',
                'TIME', 'ADDRESS', 'CITE', 'Q',
                'ABBR', 'DFN', 'SUB', 'SUP',
                'DETAILS', 'SUMMARY'
            ]);

            // Attributes worth keeping (provide useful info)
            const USEFUL_ATTRIBUTES = new Set([
                'alt',           // Image descriptions
                'title',         // Tooltips/descriptions
                'href',          // Links
                'src',           // Image sources (simplified)
                'placeholder',   // Form field hints
                'value',         // Form values
                'name',          // Form field names
                'type',          // Input types
                'datetime',      // Time elements
                'cite',          // Citation sources
                'label',         // Option labels
                'summary',       // Table summaries
                'scope',         // Table header scope
                'colspan',       // Table structure
                'rowspan',       // Table structure
                'headers',       // Table accessibility
                'lang',          // Language hints
                'dir',           // Text direction
                'aria-label',    // Accessibility labels
                'aria-describedby',
                'role'           // Accessibility roles
            ]);

            // Helper to check if element is visible
            const isVisible = (el) => {
                if (!el || el.nodeType !== Node.ELEMENT_NODE) return true;
                const style = window.getComputedStyle(el);
                return style.display !== 'none' 
                    && style.visibility !== 'hidden' 
                    && style.opacity !== '0';
            };

            // Clean and extract content from element
            const processElement = (element) => {
                if (!element || element.nodeType === Node.COMMENT_NODE) {
                    return '';
                }

                // Text node - return trimmed text
                if (element.nodeType === Node.TEXT_NODE) {
                    const text = element.textContent.trim();
                    return text ? text + ' ' : '';
                }

                // Not an element node
                if (element.nodeType !== Node.ELEMENT_NODE) {
                    return '';
                }

                const tagName = element.tagName;

                // Skip removed tags entirely
                if (REMOVE_TAGS.has(tagName)) {
                    return '';
                }

                // Skip hidden elements
                if (!isVisible(element)) {
                    return '';
                }

                // Process children first
                let childContent = '';
                for (const child of element.childNodes) {
                    childContent += processElement(child);
                }
                childContent = childContent.trim();

                // For semantic tags, wrap content in the tag with useful attributes
                if (SEMANTIC_TAGS.has(tagName)) {
                    // Build attributes string with only useful ones
                    let attrs = '';
                    for (const attr of element.attributes) {
                        const attrName = attr.name.toLowerCase();
                        if (USEFUL_ATTRIBUTES.has(attrName) && attr.value.trim()) {
                            // Simplify src to just show it exists or filename
                            let attrValue = attr.value.trim();
                            if (attrName === 'src') {
                                // Extract just the filename or indicate external
                                try {
                                    const urlObj = new URL(attrValue, window.location.href);
                                    const pathname = urlObj.pathname;
                                    attrValue = pathname.split('/').pop() || '[image]';
                                } catch {
                                    attrValue = '[image]';
                                }
                            }
                            // Escape quotes in attribute values
                            attrValue = attrValue.replace(/"/g, '&quot;');
                            attrs += ` ${attrName}="${attrValue}"`;
                        }
                    }

                    // Special handling for self-closing/empty tags
                    if (tagName === 'IMG') {
                        // IMG tags are valuable for alt text
                        const alt = element.getAttribute('alt')?.trim();
                        const title = element.getAttribute('title')?.trim();
                        if (alt || title) {
                            return `<img${attrs}> `;
                        }
                        return ''; // Skip images without alt/title
                    }

                    if (tagName === 'BR') {
                        return '\n';
                    }

                    if (tagName === 'HR') {
                        return '\n---\n';
                    }

                    // INPUT, BUTTON handling
                    if (tagName === 'INPUT') {
                        const type = element.getAttribute('type') || 'text';
                        const value = element.getAttribute('value')?.trim();
                        const placeholder = element.getAttribute('placeholder')?.trim();
                        const name = element.getAttribute('name')?.trim();
                        if (value || placeholder || name) {
                            return `<input${attrs}> `;
                        }
                        return '';
                    }

                    if (tagName === 'BUTTON' && childContent) {
                        return `<button${attrs}>${childContent}</button> `;
                    }

                    // Skip empty containers (div, span without content or useful attrs)
                    if ((tagName === 'DIV' || tagName === 'SPAN') && !childContent && !attrs) {
                        return '';
                    }

                    // Unwrap div/span if they only have content, no useful attributes
                    if ((tagName === 'DIV' || tagName === 'SPAN') && childContent && !attrs) {
                        // Keep semantic structure with line breaks for divs
                        return tagName === 'DIV' ? childContent + '\n' : childContent;
                    }

                    // Skip empty tags (except self-closing ones already handled)
                    if (!childContent && !attrs) {
                        return '';
                    }

                    // Block-level tags get newlines
                    const blockTags = new Set([
                        'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 
                        'P', 'BLOCKQUOTE', 'PRE', 
                        'UL', 'OL', 'LI',
                        'TABLE', 'TR', 'THEAD', 'TBODY',
                        'DL', 'DT', 'DD',
                        'ARTICLE', 'SECTION', 'HEADER', 'MAIN', 'NAV', 'ASIDE',
                        'FIGURE', 'FIGCAPTION',
                        'FORM', 'DETAILS', 'SUMMARY'
                    ]);

                    const isBlock = blockTags.has(tagName);
                    const prefix = isBlock ? '\n' : '';
                    const suffix = isBlock ? '\n' : ' ';

                    // Build the tag
                    const lowerTag = tagName.toLowerCase();
                    if (childContent || attrs) {
                        return `${prefix}<${lowerTag}${attrs}>${childContent}</${lowerTag}>${suffix}`;
                    }
                    return '';
                }

                // Non-semantic tags - just return child content
                return childContent ? childContent + ' ' : '';
            };

            // Process body
            const result = processElement(document.body);

            // Clean up the result
            return result
                .replace(/[ \t]+/g, ' ')           // Multiple spaces to single
                .replace(/\n\s*\n\s*\n/g, '\n\n')  // Multiple newlines to double
                .replace(/^\s+|\s+$/gm, '')        // Trim each line
                .replace(/> +</g, '><')            // Remove space between tags
                .replace(/>\s+/g, '>')             // Remove space after opening tags
                .replace(/\s+</g, '<')             // Remove space before closing tags
                .replace(/<(\w+)([^>]*)>\s*<\/\1>/g, '') // Remove empty tags
                .trim();
        });

        // Get response status
        const status = response?.status() || null;
        const statusText = response?.statusText() || null;

        if (outputJson) {
            const result = {
                url: url,
                status: status,
                statusText: statusText,
                title: title,
                metaDescription: metaDescription,
                metaKeywords: metaKeywords,
                loadTimeMs: loadTime,
                contentLength: cleanedHtml.length,
                rawHtmlLength: rawHtml.length,
                content: cleanedHtml,
                rawHtml: rawHtml,
                extractedUrls: extractedUrls,
            };
            console.log(JSON.stringify(result, null, 2));
        } else {
            console.log('═'.repeat(60));
            console.log(`URL: ${url}`);
            console.log(`Status: ${status} ${statusText}`);
            console.log(`Title: ${title}`);
            if (metaDescription) {
                console.log(`Description: ${metaDescription}`);
            }
            console.log(`Load time: ${loadTime}ms`);
            console.log(`Content length: ${cleanedHtml.length} characters`);
            console.log('═'.repeat(60));
            console.log('');
            console.log(cleanedHtml);
        }

        await browser.close();
        process.exit(0);

    } catch (error) {
        if (browser) {
            await browser.close();
        }
        
        if (outputJson) {
            console.log(JSON.stringify({
                url: url,
                error: error.message,
                errorType: error.name,
            }, null, 2));
        } else {
            console.error(`Error: ${error.message}`);
        }
        
        process.exit(1);
    }
}

extractText();
