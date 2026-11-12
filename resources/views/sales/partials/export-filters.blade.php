{{--
    Builds an export URL that carries the sales table's current filters, so "Export"
    downloads the same sales the page is showing.

    It reads the parameters of the table's last AJAX request (DataTables ajax.params()),
    i.e. exactly what the page's data() function sent: status, type, date range / flock,
    category, title, office, user, source, CV limit, plus the search box.
--}}
<script>
    window.salesExportUrl = function(href, tableSelector) {
        const url = new URL(href, window.location.origin);
        const params = $.fn.DataTable.isDataTable(tableSelector)
            ? ($(tableSelector).DataTable().ajax.params() || {})
            : {};

        [
            'status_filter', 'type_filter', 'category_filter', 'source_filter', 'title_filter',
            'office_filter', 'user_filter', 'cv_limit_filter', 'blacklist_filter', 'date_range_filter', 'date_flock_filter'
        ].forEach(function(key) {
            const value = params[key];
            url.searchParams.delete(key);
            url.searchParams.delete(key + '[]');

            if (Array.isArray(value)) {
                value.filter(v => v !== '' && v !== null && typeof v !== 'undefined')
                    .forEach(v => url.searchParams.append(key + '[]', v));
            } else if (value !== null && typeof value !== 'undefined') {
                url.searchParams.set(key, value);
            }
        });

        // Always sent (even empty) so the export applies the page's filters and date defaults.
        url.searchParams.set('search', ((params.search && params.search.value) || '').toString().trim());

        return url.toString();
    };
</script>
