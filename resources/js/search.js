/**
 * Vector Search page functionality
 * Handles form submission, API calls, and results display
 */

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('search-form');
    const input = document.getElementById('search-input');
    const btn = document.getElementById('search-btn');
    const resultsContainer = document.getElementById('results');
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

            displayResults(data.results, data.query_time_ms);

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

    function displayResults(results, queryTimeMs) {
        if (!results || results.length === 0) {
            resultsContainer.innerHTML = `
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172a4 4 0 015.656 0M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <h3>No Results Found</h3>
                    <p>Try adjusting your search terms or check if pages have embeddings</p>
                </div>
            `;
            return;
        }

        const timeInfo = queryTimeMs ? `<span class="query-time">${queryTimeMs}ms</span>` : '';

        const resultsHtml = `
            <div class="results-header">
                <span class="results-count">Found <strong>${results.length}</strong> result${results.length !== 1 ? 's' : ''}</span>
                ${timeInfo}
            </div>
            ${results.map((result, index) => createResultCard(result, index + 1)).join('')}
        `;

        resultsContainer.innerHTML = resultsHtml;
    }

    function createResultCard(result, rank) {
        // Convert score to percentage (score is 0-1)
        const similarityPercent = (result.score * 100).toFixed(1);
        
        // Color based on similarity
        const scoreClass = result.score >= 0.7 ? 'score-high' : 
                          result.score >= 0.5 ? 'score-medium' : 'score-low';

        return `
            <div class="result-card">
                <div class="result-header">
                    <div class="result-rank">#${rank}</div>
                    <div class="result-info">
                        <h3 class="result-title">
                            <a href="${escapeHtml(result.url)}" target="_blank" rel="noopener">
                                ${escapeHtml(result.title || 'Untitled')}
                            </a>
                        </h3>
                        <div class="result-url">${escapeHtml(truncateUrl(result.url))}</div>
                    </div>
                    <div class="result-score ${scoreClass}">${similarityPercent}%</div>
                </div>
                ${result.recap_content ? `<p class="result-recap">${escapeHtml(result.recap_content)}</p>` : ''}
                ${result.summary ? `<p class="result-summary">${escapeHtml(result.summary)}</p>` : ''}
                <div class="result-footer">
                    ${result.page_type ? `<span class="result-type">${escapeHtml(result.page_type)}</span>` : ''}
                    <span class="result-distance" title="Cosine distance: ${result.distance}">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="14" height="14">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z" />
                        </svg>
                        Semantic match
                    </span>
                </div>
            </div>
        `;
    }

    function truncateUrl(url) {
        if (!url) return '';
        try {
            const urlObj = new URL(url);
            let path = urlObj.pathname;
            if (path.length > 50) {
                path = path.substring(0, 47) + '...';
            }
            return urlObj.host + path;
        } catch {
            return url.length > 60 ? url.substring(0, 57) + '...' : url;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
