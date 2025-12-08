/**
 * Search page functionality
 * Handles form submission, API calls, and results display
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('search-form');
    const input = document.getElementById('search-input');
    const btn = document.getElementById('search-btn');
    const resultsContainer = document.getElementById('results');
    const parsedTagsContainer = document.getElementById('parsed-tags');
    const tagsList = document.getElementById('tags-list');
    const errorMessage = document.getElementById('error-message');

    let isSearching = false;

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const query = input.value.trim();
        if (!query || isSearching) return;

        await performSearch(query);
    });

    async function performSearch(query) {
        isSearching = true;
        setLoading(true);
        hideError();
        hideParsedTags();

        try {
            const response = await fetch('/search', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ query }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.message || 'Search failed');
            }

            if (data.error) {
                showError(data.error);
                return;
            }

            displayParsedTags(data.tags);
            displayResults(data.results);

        } catch (error) {
            console.error('Search error:', error);
            showError(error.message || 'An error occurred while searching');
        } finally {
            isSearching = false;
            setLoading(false);
        }
    }

    function setLoading(loading) {
        if (loading) {
            btn.disabled = true;
            btn.innerHTML = '<div class="spinner"></div><span>Searching...</span>';
        } else {
            btn.disabled = false;
            btn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                </svg>
                <span>Search</span>
            `;
        }
    }

    function showError(message) {
        errorMessage.textContent = message;
        errorMessage.classList.add('visible');
    }

    function hideError() {
        errorMessage.classList.remove('visible');
    }

    function displayParsedTags(tags) {
        if (!tags || Object.keys(tags).length === 0) {
            hideParsedTags();
            return;
        }

        tagsList.innerHTML = '';

        // Sort tags by weight descending
        const sortedTags = Object.entries(tags).sort((a, b) => b[1] - a[1]);

        sortedTags.forEach(([tag, weight]) => {
            const badge = document.createElement('div');
            badge.className = 'tag-badge';
            badge.innerHTML = `
                <span class="tag-name">${escapeHtml(tag)}</span>
                <span class="tag-weight">${weight}</span>
            `;
            tagsList.appendChild(badge);
        });

        parsedTagsContainer.classList.add('visible');
    }

    function hideParsedTags() {
        parsedTagsContainer.classList.remove('visible');
    }

    function displayResults(results) {
        if (!results || results.length === 0) {
            resultsContainer.innerHTML = `
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172a4 4 0 015.656 0M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <h3>No Results Found</h3>
                    <p>Try adjusting your search terms</p>
                </div>
            `;
            return;
        }

        const resultsHtml = `
            <div class="results-header">
                <span class="results-count">Found <strong>${results.length}</strong> result${results.length !== 1 ? 's' : ''}</span>
            </div>
            ${results.map(result => createResultCard(result)).join('')}
        `;

        resultsContainer.innerHTML = resultsHtml;
    }

    function createResultCard(result) {
        const matchedTagsHtml = result.matched_tags && result.matched_tags.length > 0
            ? `
                <div class="matched-tags">
                    <div class="matched-tags-title">Matched Tags</div>
                    ${result.matched_tags.map(tag => `
                        <span class="matched-tag">
                            ${escapeHtml(tag.db_tag)}
                            <small>(${tag.db_weight})</small>
                        </span>
                    `).join('')}
                </div>
            `
            : '';

        return `
            <div class="result-card">
                <div class="result-header">
                    <div>
                        <h3 class="result-title">
                            <a href="${escapeHtml(result.url)}" target="_blank" rel="noopener">
                                ${escapeHtml(result.title || 'Untitled')}
                            </a>
                        </h3>
                        <div class="result-url">${escapeHtml(result.url)}</div>
                    </div>
                    <div class="result-score">${(result.score * 100).toFixed(1)}%</div>
                </div>
                ${result.summary ? `<p class="result-summary">${escapeHtml(result.summary)}</p>` : ''}
                ${result.page_type ? `<span class="result-type">${escapeHtml(result.page_type)}</span>` : ''}
                ${matchedTagsHtml}
            </div>
        `;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});

