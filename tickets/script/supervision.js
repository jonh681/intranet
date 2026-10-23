'use strict';

// Apply select, date and status filters immediately; the search text still uses the Aplicar button or Enter.
const filters = document.getElementById('filters');
if (filters) {
    filters.addEventListener('change', event => {
        if (event.target.name !== 'q') filters.requestSubmit();
    });
}
