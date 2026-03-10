(function($) {
    'use strict';

    // Indlæs dropdown-værdier ved pageload
    $(document).ready(function() {
        loadDropdowns();

        // Auto-åbn import-dialog med eksisterende gruppe pre-selected
        var urlParams = new URLSearchParams(window.location.search);
        var addToGroup = urlParams.get('add_to_group');
        if (addToGroup) {
            window.pfAutoAddToGroup = addToGroup;
            $('#pf-auto-add-notice').show();
        }
    });

    function loadDropdowns() {
        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_search_products',
            nonce: pfAdmin.nonce,
            query: ''
        }, function(res) {
            if (!res.success) return;
            populateSelect('#pf-search-merchant', res.data.merchants);
            populateSelect('#pf-search-category', res.data.categories);
            populateSelect('#pf-search-brand', res.data.brands);
        });
    }

    function populateSelect(selector, items) {
        var $el = $(selector);
        var current = $el.val();
        $el.find('option:not(:first)').remove();
        $.each(items, function(_, val) {
            $el.append($('<option>').val(val).text(val));
        });
        if (current) $el.val(current);
    }

    // Søg
    $('#pf-search-btn').on('click', doSearch);
    $('#pf-search-query').on('keypress', function(e) {
        if (e.which === 13) doSearch();
    });

    function doSearch() {
        var $body = $('#pf-results-body');
        var $table = $('#pf-results-table');
        var $none = $('#pf-no-results');
        var $loading = $('#pf-loading');

        $table.hide();
        $none.hide();
        $loading.show();

        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_search_products',
            nonce: pfAdmin.nonce,
            query: $('#pf-search-query').val(),
            merchant: $('#pf-search-merchant').val(),
            category: $('#pf-search-category').val(),
            brand: $('#pf-search-brand').val(),
            price_min: $('#pf-search-price-min').val(),
            price_max: $('#pf-search-price-max').val(),
            stock: $('#pf-search-stock').val()
        }, function(res) {
            $loading.hide();

            if (!res.success || !res.data.products.length) {
                $none.show();
                $('#pf-result-count').text('(0)');
                return;
            }

            $body.empty();
            $.each(res.data.products, function(_, p) {
                var oldPrice = (p.old_price > 0 && p.old_price > p.price)
                    ? '<del>' + parseFloat(p.old_price).toFixed(2) + ' kr</del>'
                    : '-';
                var stockClass = (p.stock_status && p.stock_status.match(/in.stock|lager|yes|^1$|^true$/i))
                    ? 'pf-instock' : 'pf-outofstock';
                var stockLabel = stockClass === 'pf-instock' ? 'På lager' : 'Udsolgt';

                $body.append(
                    '<tr data-id="' + p.id + '">' +
                    '<td><input type="checkbox" class="pf-product-check" value="' + p.id + '"></td>' +
                    '<td><img src="' + (p.image_url || '') + '" style="width:50px;height:50px;object-fit:cover;" onerror="this.style.display=\'none\'"></td>' +
                    '<td><strong>' + escHtml(p.name) + '</strong></td>' +
                    '<td>' + escHtml(p.webshop) + '</td>' +
                    '<td>' + escHtml(p.merchant) + '</td>' +
                    '<td>' + escHtml(p.category) + '</td>' +
                    '<td>' + escHtml(p.brand) + '</td>' +
                    '<td><strong>' + parseFloat(p.price).toFixed(2) + ' kr</strong></td>' +
                    '<td>' + oldPrice + '</td>' +
                    '<td>' + (parseFloat(p.shipping) > 0 ? p.shipping + ' kr' : 'Gratis') + '</td>' +
                    '<td><span class="' + stockClass + '">' + stockLabel + '</span></td>' +
                    '</tr>'
                );
            });

            $table.show();
            $('#pf-result-count').text('(' + res.data.total + ')');

            populateSelect('#pf-search-merchant', res.data.merchants);
            populateSelect('#pf-search-category', res.data.categories);
            populateSelect('#pf-search-brand', res.data.brands);
        });
    }

    // Vælg alle
    $('#pf-check-all').on('change', function() {
        $('.pf-product-check').prop('checked', this.checked);
    });

    $('#pf-select-all').on('click', function() {
        $('.pf-product-check').prop('checked', true);
        $('#pf-check-all').prop('checked', true);
    });

    function getSelectedIds() {
        return $('.pf-product-check:checked').map(function() {
            return parseInt(this.value);
        }).get();
    }

    // =========================================================================
    // Import-dialog: ny/eksisterende gruppe toggle
    // =========================================================================

    $('input[name="pf_group_mode"]').on('change', function() {
        if ($(this).val() === 'existing') {
            $('#pf-new-group-fields').hide();
            $('#pf-existing-group-fields').show();
            loadExistingGroups();
        } else {
            $('#pf-new-group-fields').show();
            $('#pf-existing-group-fields').hide();
        }
    });

    function loadExistingGroups() {
        var $sel = $('#pf-existing-filter');
        $sel.html('<option value="">Indlæser...</option>');

        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_list_filters',
            nonce: pfAdmin.nonce
        }, function(res) {
            $sel.empty();
            if (!res.success || !res.data.filters.length) {
                $sel.append('<option value="">Ingen grupper oprettet</option>');
                return;
            }
            $sel.append('<option value="">— Vælg gruppe —</option>');
            $.each(res.data.filters, function(_, f) {
                $sel.append('<option value="' + f.id + '">' + escHtml(f.name) + '</option>');
            });
        });
    }

    // Åbn import-dialog
    $('#pf-import-btn').on('click', function() {
        var ids = getSelectedIds();
        if (!ids.length) {
            alert('Vælg mindst ét produkt at importere.');
            return;
        }

        if (window.pfAutoAddToGroup) {
            // Pre-select "eksisterende gruppe" med den valgte gruppe
            $('#pf-mode-existing').prop('checked', true).trigger('change');
            preSelectGroup(window.pfAutoAddToGroup);
        } else {
            // Reset til "ny gruppe"
            $('#pf-mode-new').prop('checked', true).trigger('change');
        }
        $('#pf-save-dialog').show();
    });

    function preSelectGroup(groupId) {
        var $sel = $('#pf-existing-filter');
        $sel.html('<option value="">Indlæser...</option>');

        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_list_filters',
            nonce: pfAdmin.nonce
        }, function(res) {
            $sel.empty();
            if (!res.success || !res.data.filters.length) {
                $sel.append('<option value="">Ingen grupper oprettet</option>');
                return;
            }
            $sel.append('<option value="">— Vælg gruppe —</option>');
            $.each(res.data.filters, function(_, f) {
                $sel.append('<option value="' + f.id + '">' + escHtml(f.name) + '</option>');
            });
            $sel.val(groupId);
        });
    }

    $('#pf-save-cancel').on('click', function() {
        $('#pf-save-dialog').hide();
    });

    // Bekræft: Gem/tilføj + importer
    $('#pf-save-confirm').on('click', function() {
        var mode = $('input[name="pf_group_mode"]:checked').val();
        var ids = getSelectedIds();
        var $btn = $(this).prop('disabled', true).text('Importerer...');
        $('#pf-save-cancel').prop('disabled', true);

        if (mode === 'existing') {
            handleAddToExisting(ids, $btn);
        } else {
            handleCreateNew(ids, $btn);
        }
    });

    function handleCreateNew(ids, $btn) {
        var name = $('#pf-filter-name').val().trim();
        if (!name) {
            alert('Indtast et navn for produktgruppen.');
            resetBtn($btn);
            return;
        }

        // Trin 1: Opret produktgruppe
        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_save_filter',
            nonce: pfAdmin.nonce,
            name: name,
            query: $('#pf-search-query').val(),
            merchant: $('#pf-search-merchant').val(),
            category: $('#pf-search-category').val(),
            brand: $('#pf-search-brand').val(),
            price_min: $('#pf-search-price-min').val(),
            price_max: $('#pf-search-price-max').val(),
            stock: $('#pf-search-stock').val(),
            product_ids: ids
        }, function(filterRes) {
            if (!filterRes.success) {
                alert('Fejl: ' + (filterRes.data || 'Ukendt fejl'));
                resetBtn($btn);
                return;
            }
            doImport(ids, $btn, filterRes.data.shortcode, 'Produktgruppe oprettet!', filterRes.data.filter_id);
        });
    }

    function handleAddToExisting(ids, $btn) {
        var filterId = $('#pf-existing-filter').val();
        if (!filterId) {
            alert('Vælg en produktgruppe.');
            resetBtn($btn);
            return;
        }

        // Trin 1: Tilføj til eksisterende gruppe
        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_add_to_filter',
            nonce: pfAdmin.nonce,
            filter_id: filterId,
            product_ids: ids
        }, function(filterRes) {
            if (!filterRes.success) {
                alert('Fejl: ' + (filterRes.data || 'Ukendt fejl'));
                resetBtn($btn);
                return;
            }
            var msg = filterRes.data.added + ' nye produkter tilføjet (' + filterRes.data.total + ' i alt).';
            doImport(ids, $btn, filterRes.data.shortcode, msg, filterRes.data.filter_id);
        });
    }

    function doImport(ids, $btn, shortcode, groupMsg, filterId) {
        // Trin 2: Importer til WooCommerce
        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_import_products',
            nonce: pfAdmin.nonce,
            product_ids: ids
        }, function(importRes) {
            if (importRes.success && importRes.data.wc_ids && filterId) {
                // Trin 3: Opdater produktgruppen med WooCommerce-IDs
                $.post(pfAdmin.ajaxUrl, {
                    action: 'pf_update_filter_wc_ids',
                    nonce: pfAdmin.nonce,
                    filter_id: filterId,
                    wc_ids: importRes.data.wc_ids
                });
            }

            $('#pf-save-dialog').hide();
            resetBtn($btn);
            $('#pf-filter-name').val('');

            if (importRes.success) {
                alert(
                    groupMsg + '\n' +
                    'Shortcode: ' + shortcode + '\n\n' +
                    'WooCommerce: ' + importRes.data.imported + ' nye, ' + importRes.data.updated + ' opdateret.'
                );
            } else {
                alert('Gruppe gemt, men import fejlede: ' + (importRes.data || 'Ukendt fejl'));
            }
        });
    }

    function resetBtn($btn) {
        $btn.prop('disabled', false).text('Gem & Importer');
        $('#pf-save-cancel').prop('disabled', false);
    }

    // =========================================================================
    // Opdater feed
    // =========================================================================

    $(document).on('click', '.pf-refresh-feed', function() {
        var $btn = $(this);
        var url = $btn.data('url');
        var source = $btn.data('source');

        $btn.prop('disabled', true).text('Opdaterer...');

        $.post(pfAdmin.ajaxUrl, {
            action: 'pf_refresh_feed',
            nonce: pfAdmin.nonce,
            feed_url: url,
            source: source
        }, function(res) {
            $btn.prop('disabled', false).text('Opdater nu');
            if (res.success) {
                alert(res.data.message);
                location.reload();
            } else {
                alert('Fejl: ' + (res.data || 'Ukendt fejl'));
            }
        });
    });

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
