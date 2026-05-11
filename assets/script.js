(function ($)
{
  // ── Pomocné funkce ──────────────────────────────────────────

  function isTouchDevice()
  {
    return window.matchMedia('(hover: none) and (pointer: coarse)').matches;
  }

  const panelImg = document.getElementById('sp-panel-img');
  const flash    = document.getElementById('sp-projector-flash');

  function switchImage(newSrc)
  {
    if ( ! panelImg || panelImg.getAttribute('src') === newSrc ) return;

    flash.classList.add('flash-on');

    setTimeout(function ()
    {
      panelImg.src = newSrc;

      setTimeout(function ()
      {
        flash.classList.remove('flash-on');
      }, 80);

    }, 90);
  }

  // ── AJAX přidání do košíku ───────────────────────────────────

  function addToCart(productId, variationId, qty, variationAttrs, btn)
  {
    const originalText = btn.textContent;
    btn.disabled       = true;
    btn.textContent    = '…';

    if (typeof SP_Archive === 'undefined' || ! SP_Archive.ajax_url)
    {
      console.error('❌ SP_Archive.ajax_url není dostupný');
      btn.textContent = 'Chyba konfigurace';
      btn.disabled    = false;
      return;
    }

    const data = {
      action:      'sp_add_to_cart',
      security:    SP_Archive.sp_nonce,
      product_id:  productId,
      quantity:    qty,
    };

    if (variationId)
    {
      data.variation_id = variationId;
    }

    if (variationAttrs && typeof variationAttrs === 'object')
    {
      Object.keys(variationAttrs).forEach(function (key)
      {
        data[key] = variationAttrs[key];
      });
    }

    console.group('🛒 addToCart – odesílám přes admin-ajax sp_add_to_cart');
    console.log('data:', data);
    console.groupEnd();

    $.ajax(
    {
      url:    SP_Archive.ajax_url,
      method: 'POST',
      data:   data,
      success: function (response)
      {
        console.log('✅ response:', response);

        if ( ! response || ! response.success)
        {
          const rawMsg = (response && response.data && response.data.message) || 'Chyba';
          // Dekódovat HTML entity (např. &mdash; → –)
          const tmp = document.createElement('div');
          tmp.innerHTML = rawMsg;
          const msg = tmp.textContent || tmp.innerText || rawMsg;
          console.error('❌ Chyba:', msg);

          // Rozlišit chybu skladu od ostatních chyb
          const isStockError = /skladem|košíku|množství/i.test(msg);
          if (isStockError) {
            // Zobrazit hláška o skladu pod tlačítkem
            var stockMsg = btn.closest('.sp-inline-bottom-row, .sp-action-row, .sp-inline-actions, .sp-mobile-panel');
            if (stockMsg) {
              var existing = stockMsg.querySelector('.sp-stock-notice');
              if ( ! existing) {
                existing = document.createElement('p');
                existing.className = 'sp-stock-notice';
                existing.style.cssText = 'color:#cc0000;font-size:0.88rem;margin:6px 0 0;width:100%;';
                stockMsg.appendChild(existing);
              }
              existing.textContent = 'Bohužel, více kusů není skladem.';
              setTimeout(function () {
                if (existing.parentNode) existing.parentNode.removeChild(existing);
              }, 4000);
            }
            btn.textContent = originalText;
            btn.disabled    = false;
          } else {
            btn.textContent = 'Chyba';
            setTimeout(function () { btn.textContent = originalText; btn.disabled = false; }, 2500);
          }
          return;
        }

        if (response.data && response.data.fragments)
        {
          $.each(response.data.fragments, function (key, value)
          {
            if ($(key).length) $(key).replaceWith(value);
          });
          $(document.body).trigger('wc_fragments_refreshed');
        }

        btn.textContent = '✓ Přidáno';
        setTimeout(function ()
        {
          btn.textContent = originalText;
          btn.disabled    = false;
        }, 2000);
      },
      error: function (jqXHR, textStatus)
      {
        console.error('💥 AJAX selhal:', textStatus, jqXHR.status);
        btn.textContent = 'Chyba spojení';
        setTimeout(function () { btn.textContent = originalText; btn.disabled = false; }, 2000);
      }
    });
  }

  // ── Resolve inline variace ───────────────────────────────────

  function resolveInlineVariation(item, btn)
  {
    const type       = item.dataset.type;
    const variations = JSON.parse(item.dataset.variations || '[]');

    console.group('🔍 resolveInlineVariation – produkt #' + item.dataset.id + ' (' + item.dataset.name + ')');
    console.log('Typ produktu:', type);
    console.log('Variace z data-variations:', variations);

    if (type !== 'variable')
    {
      console.log('→ Jednoduchý produkt, žádná varianta potřeba.');
      console.groupEnd();
      return { variationId: null, attrs: {} };
    }

    const panel   = (btn && (btn.closest('.sp-inline-actions') || btn.closest('.sp-mobile-panel'))) || item;
    const selects = panel.querySelectorAll('.sp-inline-variation-select, .sp-variation-select');
    const selected = {};

    selects.forEach(function (sel)
    {
      selected[sel.dataset.attribute] = sel.value;
    });

    console.log('Panel:', panel.className);
    console.log('Nalezené selecty (' + selects.length + '):', Array.from(selects).map(function(s){ return { class: s.className, attribute: s.dataset.attribute, value: s.value }; }));
    console.log('Vybrané hodnoty:', selected);

    const allChosen = Object.values(selected).every(function (v) { return v !== ''; });
    console.log('Všechny atributy vybrány?', allChosen);

    if ( ! allChosen )
    {
      const missing = Object.entries(selected).filter(function([k,v]){ return v === ''; }).map(function([k]){ return k; });
      console.warn('⚠️ Chybí výběr u atributů:', missing);
      console.groupEnd();
      return { variationId: null, attrs: selected, incomplete: true };
    }

    const match = variations.find(function (v)
    {
      return Object.keys(selected).every(function (key)
      {
        return v.attributes[key] === '' || v.attributes[key] === selected[key];
      });
    });

    console.log('Hledám shodu pro:', selected);
    console.log('Nalezená variace:', match || '❌ žádná shoda');

    if ( ! match )
    {
      console.warn('⚠️ Žádná variace neodpovídá vybraným atributům.');
      console.log('Dostupné kombinace atributů:');
      variations.forEach(function(v, i){
        console.log('  [' + i + ']', v.attributes, '→ in_stock:', v.in_stock, '| id:', v.id);
      });
      console.groupEnd();
      return { variationId: null, attrs: selected, noMatch: true };
    }

    if ( match.in_stock === false )
    {
      console.warn('⚠️ Variace nalezena, ale není skladem. ID:', match.id);
      console.groupEnd();
      return { variationId: match.id, attrs: selected, outOfStock: true };
    }

    console.log('✅ Variace nalezena a skladem. ID:', match.id);
    console.groupEnd();

    // Obnovit pa_ prefix pro taxonomy atributy.
    // PHP archive-product.php normalizuje attribute_pa_varianty → attribute_varianty
    // aby JS selecty seděly. Před odesláním na server musíme prefix obnovit,
    // jinak WC()->cart->add_to_cart() vrátí "Neplatná hodnota pro Varianty".
    const attrsWithPa = {};
    Object.keys(match.attributes).forEach(function(key) {
      const paKey = key.replace(/^attribute_(?!pa_)/, 'attribute_pa_');
      attrsWithPa[paKey] = match.attributes[key];
    });

    return { variationId: match.id, attrs: attrsWithPa };
  }

  // ── Inicializace ─────────────────────────────────────────────

  $(document).ready(function ()
  {
    const items = document.querySelectorAll('.sp-product-item');
    if ( ! items.length ) return;

    items.forEach(function (item)
    {
      if (item.querySelector('.fb-bundle-preview'))
      {
        item.classList.add('has-bundle');
      }
    });

    // ── CFB Bundle Modal ────────────────────────────────────────

    var cfbBundleModal = document.getElementById('sp-cfb-bundle-modal');
    if ( ! cfbBundleModal)
    {
      cfbBundleModal = document.createElement('div');
      cfbBundleModal.id = 'sp-cfb-bundle-modal';
      cfbBundleModal.setAttribute('role', 'dialog');
      cfbBundleModal.setAttribute('aria-modal', 'true');
      cfbBundleModal.innerHTML =
        '<div id="sp-cfb-bundle-backdrop"></div>' +
        '<div id="sp-cfb-bundle-dialog">' +
          '<button id="sp-cfb-bundle-close" aria-label="Zavřít">&times;</button>' +
          '<h2 id="sp-cfb-bundle-title"></h2>' +
          '<div id="sp-cfb-bundle-body"></div>' +
          '<div id="sp-cfb-bundle-footer">' +
            '<button id="sp-cfb-bundle-add" class="custom-product-btn" disabled>' +
              'PŘIDAT DO KOŠÍKU' +
            '</button>' +
          '</div>' +
          '<div id="sp-cfb-bundle-msg"></div>' +
        '</div>';
      document.body.appendChild(cfbBundleModal);
    }

    var cfbCurrentProductId  = null;
    var cfbCurrentPermalink  = null;
    var cfbRequiredQty       = 0;
    var cfbPollInterval      = null;
    var cfbAddToCartNonce    = null;

    function cfbGetSectionInfoFromDom(plusBtn)
    {
      var bodyEl     = document.getElementById('sp-cfb-bundle-body');
      var allButtons = bodyEl ? bodyEl.querySelectorAll('.cfb-plus[data-flavor-id]') : [];
      if ( ! bodyEl || allButtons.length === 0) return null;

      var el = plusBtn.parentElement;

      while (el && el !== bodyEl)
      {
        var buttons = el.querySelectorAll('.cfb-plus[data-flavor-id]');

        if (buttons.length > 0 && buttons.length < allButtons.length)
        {
          var limitMatch = el.textContent.match(/Limit[:\s]+(\d+)/i);
          if (limitMatch)
          {
            var sectionTotal = 0;
            buttons.forEach(function (btn)
            {
              var qtyEl = btn.previousElementSibling;
              if (qtyEl)
              {
                sectionTotal += qtyEl.tagName === 'INPUT'
                  ? parseInt(qtyEl.value        || 0, 10)
                  : parseInt(qtyEl.textContent  || 0, 10);
              }
            });
            return { limit: parseInt(limitMatch[1], 10), total: sectionTotal };
          }
        }

        el = el.parentElement;
      }

      return null;
    }

    document.addEventListener('click', function (e)
    {
      var plusBtn = e.target.closest('.cfb-plus');
      if ( ! plusBtn) return;
      if ( ! cfbBundleModal.classList.contains('sp-cfb-bundle-open')) return;

      var selInput  = document.getElementById('cfb_flavor_selection');
      var rawBefore = selInput ? selInput.value : null;

      setTimeout(function ()
      {
        var rawAfter = selInput ? selInput.value : null;

        if (rawAfter !== rawBefore) return;

        if ( ! rawAfter) return;

        var sel;
        try { sel = JSON.parse(rawAfter); }
        catch (e) { return; }

        var total = Object.values(sel).reduce(function (s, v)
        {
          return s + parseInt(v.qty || 0, 10);
        }, 0);

        if (cfbRequiredQty <= 0 || total >= cfbRequiredQty) return;

        var flavorId = plusBtn.dataset.flavorId || plusBtn.getAttribute('data-flavor-id');
        if ( ! flavorId || sel[flavorId] === undefined) return;

        var sectionInfo = cfbGetSectionInfoFromDom(plusBtn);
        if ( ! sectionInfo || sectionInfo.total >= sectionInfo.limit)
        {
          return;
        }

        sel[flavorId].qty = parseInt(sel[flavorId].qty || 0, 10) + 1;
        selInput.value = JSON.stringify(sel);

        var qtyDisplay = plusBtn.previousElementSibling;
        if (qtyDisplay)
        {
          var curDisplay = qtyDisplay.tagName === 'INPUT'
            ? parseInt(qtyDisplay.value       || 0, 10)
            : parseInt(qtyDisplay.textContent || 0, 10);
          var newDisplay = curDisplay + 1;
          if (qtyDisplay.tagName === 'INPUT') { qtyDisplay.value = newDisplay; }
          else { qtyDisplay.textContent = newDisplay; }
        }

        syncCfbAddBtn();
      }, 0);
    }, true);

    document.addEventListener('click', function (e)
    {
      var minusBtn = e.target.closest('.cfb-minus');
      if ( ! minusBtn) return;
      if ( ! cfbBundleModal.classList.contains('sp-cfb-bundle-open')) return;

      var selInput = document.getElementById('cfb_flavor_selection');
      if ( ! selInput || ! selInput.value) return;
      var rawBefore = selInput.value;

      var flavorId = minusBtn.dataset.flavorId || minusBtn.getAttribute('data-flavor-id');

      var qtyDisplay = minusBtn.nextElementSibling;
      if (qtyDisplay && qtyDisplay.tagName === 'BUTTON')
      {
        qtyDisplay = null;
      }

      var displayBefore = qtyDisplay
        ? (qtyDisplay.tagName === 'INPUT'
            ? parseInt(qtyDisplay.value       || 0, 10)
            : parseInt(qtyDisplay.textContent || 0, 10))
        : null;

      setTimeout(function ()
      {
        var rawAfter = selInput ? selInput.value : rawBefore;
        if (rawAfter === rawBefore) return;

        if (displayBefore === 0)
        {
          selInput.value = rawBefore;
          syncCfbAddBtn();
          return;
        }

        if (flavorId)
        {
          try
          {
            var selB = JSON.parse(rawBefore);
            var selA = JSON.parse(rawAfter);
            var qtyB = parseInt(((selB[flavorId] || {}).qty) || 0, 10);
            var qtyA = parseInt(((selA[flavorId] || {}).qty) || 0, 10);

            if (qtyB - qtyA > 1)
            {
              selA[flavorId].qty = Math.max(0, qtyB - 1);
              selInput.value = JSON.stringify(selA);
              syncCfbAddBtn();

              if (qtyDisplay !== null && displayBefore !== null)
              {
                var caseCDisplay = Math.max(0, displayBefore - 1);
                if (qtyDisplay.tagName === 'INPUT') { qtyDisplay.value = caseCDisplay; }
                else { qtyDisplay.textContent = caseCDisplay; }
              }
            }
          }
          catch (ex) { /* JSON parse error – fall through */ }
        }

        if (qtyDisplay !== null && displayBefore !== null && displayBefore > 0)
        {
          var displayAfter = qtyDisplay.tagName === 'INPUT'
            ? parseInt(qtyDisplay.value       || 0, 10)
            : parseInt(qtyDisplay.textContent || 0, 10);

          if (displayAfter === displayBefore)
          {
            var newDisplay = displayBefore - 1;
            if (qtyDisplay.tagName === 'INPUT') { qtyDisplay.value = newDisplay; }
            else { qtyDisplay.textContent = newDisplay; }
          }
        }
      }, 0);
    }, true);

    function syncCfbAddBtn()
    {
      var addBtn   = document.getElementById('sp-cfb-bundle-add');
      var selInput = document.getElementById('cfb_flavor_selection');
      if ( ! addBtn) return;
      if ( ! selInput || ! selInput.value) { addBtn.disabled = true; return; }

      var sel;
      try { sel = JSON.parse(selInput.value); }
      catch (e) { addBtn.disabled = true; return; }

      var total = Object.values(sel).reduce(function (s, v)
      {
        return s + parseInt(v.qty || 0, 10);
      }, 0);

      var newDisabled = cfbRequiredQty > 0 ? (total !== cfbRequiredQty) : (total === 0);

      addBtn.disabled = newDisabled;
    }

    function closeCfbBundleModal()
    {
      cfbBundleModal.classList.remove('sp-cfb-bundle-open');
      if (cfbPollInterval) { clearInterval(cfbPollInterval); cfbPollInterval = null; }
      var innerBg  = document.getElementById('cfbModalBg');
      var innerMod = document.getElementById('cfbModal');
      if (innerBg)  innerBg.style.display  = 'none';
      if (innerMod) innerMod.style.display = 'none';
    }

    document.getElementById('sp-cfb-bundle-backdrop').addEventListener('click', function (e)
    {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
    }, true);

    document.getElementById('sp-cfb-bundle-close').addEventListener('click', closeCfbBundleModal);

    document.addEventListener('keydown', function (e)
    {
      if (e.key === 'Escape' && cfbBundleModal.classList.contains('sp-cfb-bundle-open'))
      {
        closeCfbBundleModal();
      }
    });

    document.addEventListener('click', function (e)
    {
      var btn = e.target.closest('.sp-bundle-select-btn');
      if ( ! btn) return;

      e.stopPropagation();

      var item = btn.closest('.sp-product-item');
      cfbCurrentProductId = btn.dataset.productId || ( item ? item.dataset.id : null );
      cfbCurrentPermalink = item ? item.dataset.permalink : null;

      var titleEl  = document.getElementById('sp-cfb-bundle-title');
      var bodyEl   = document.getElementById('sp-cfb-bundle-body');
      var addBtn   = document.getElementById('sp-cfb-bundle-add');
      var msgEl    = document.getElementById('sp-cfb-bundle-msg');

      titleEl.textContent  = item ? item.dataset.name : '';
      bodyEl.innerHTML     = '<div class="sp-cfb-bundle-loading">Načítám…</div>';
      addBtn.disabled      = true;
      addBtn.textContent   = 'PŘIDAT DO KOŠÍKU';
      msgEl.textContent    = '';

      cfbBundleModal.classList.add('sp-cfb-bundle-open');

      $.ajax(
      {
        url:    SP_Archive.ajax_url,
        method: 'GET',
        data:
        {
          action:     'sp_cfb_bundle_ui',
          product_id: cfbCurrentProductId
        },
        success: function (response)
        {
          if ( ! response.success)
          {
            bodyEl.innerHTML = '<p>Chyba načítání výběru.</p>';
            return;
          }
          titleEl.textContent = response.data.name;
          cfbRequiredQty      = parseInt(response.data.required_qty || 0, 10);
          cfbAddToCartNonce   = response.data.add_to_cart_nonce || null;

          $(bodyEl).html(response.data.html);

          if (cfbPollInterval) clearInterval(cfbPollInterval);
          cfbPollInterval = setInterval(syncCfbAddBtn, 150);

          syncCfbAddBtn();
        },
        error: function ()
        {
          bodyEl.innerHTML = '<p>Chyba spojení.</p>';
        }
      });
    });

    document.getElementById('sp-cfb-bundle-add').addEventListener('click', function ()
    {
      if (this.disabled) return;
      if ( ! cfbCurrentProductId || ! cfbCurrentPermalink) return;

      var selectionInput = document.getElementById('cfb_flavor_selection');
      var selectionValue = selectionInput ? selectionInput.value : '';

      if ( ! selectionValue)
      {
        document.getElementById('sp-cfb-bundle-msg').textContent = 'Prosím vyberte položky balíčku.';
        return;
      }

      try
      {
        var selObj   = JSON.parse(selectionValue);
        var total    = Object.values(selObj).reduce(function (s, v) { return s + (v.qty || 0); }, 0);
        if (total === 0)
        {
          document.getElementById('sp-cfb-bundle-msg').textContent = 'Prosím vyberte položky balíčku.';
          return;
        }
      }
      catch (e) { /* necháme server validovat */ }

      var addBtn = this;
      addBtn.disabled    = true;
      addBtn.textContent = '\u2026';
      document.getElementById('sp-cfb-bundle-msg').textContent = '';

      $.ajax(
      {
        url:    SP_Archive.ajax_url,
        method: 'POST',
        data:
        {
          action:               'sp_cfb_add_to_cart',
          nonce:                cfbAddToCartNonce,
          product_id:           cfbCurrentProductId,
          cfb_flavor_selection: selectionValue
        },
        success: function (response)
        {
          if ( ! response || ! response.success)
          {
            var msg = (response && response.data && response.data.message)
              || 'Produkt se nepodařilo přidat do košíku.';
            document.getElementById('sp-cfb-bundle-msg').textContent = msg;
            addBtn.textContent = 'PŘIDAT DO KOŠÍKU';
            addBtn.disabled    = false;
            return;
          }

          var frags = response.data && response.data.fragments;
          if (frags && Object.keys(frags).length > 0)
          {
            $.each(frags, function (key, value)
            {
              if ($(key).length) $(key).replaceWith(value);
            });
            $(document.body).trigger('wc_fragments_refreshed');
          }
          else if (typeof SP_Archive !== 'undefined' && SP_Archive.wc_ajax_url)
          {
            $.ajax(
            {
              url:    SP_Archive.wc_ajax_url.replace('%%endpoint%%', 'get_refreshed_fragments'),
              method: 'POST',
              success: function (r)
              {
                if (r && r.fragments)
                {
                  $.each(r.fragments, function (key, value)
                  {
                    if ($(key).length) $(key).replaceWith(value);
                  });
                  $(document.body).trigger('wc_fragments_refreshed');
                }
              }
            });
          }

          addBtn.textContent = '✓ Přidáno';
          setTimeout(function ()
          {
            closeCfbBundleModal();
            addBtn.textContent = 'PŘIDAT DO KOŠÍKU';
            addBtn.disabled    = false;
          }, 1500);
        },
        error: function ()
        {
          document.getElementById('sp-cfb-bundle-msg').textContent = 'Chyba při přidávání do košíku.';
          addBtn.textContent = 'PŘIDAT DO KOŠÍKU';
          addBtn.disabled    = false;
        }
      });
    });

    // ── Backdrop pro fb-modal ───────────────────────────────────
    var fbModal = document.getElementById('fb-modal');
    if (fbModal)
    {
      var fbBackdrop = document.createElement('div');
      fbBackdrop.id = 'sp-fb-backdrop';
      document.body.appendChild(fbBackdrop);

      var fbObserver = new MutationObserver(function ()
      {
        fbBackdrop.classList.toggle('sp-fb-backdrop-active', fbModal.style.display === 'block');
      });
      fbObserver.observe(fbModal, { attributes: true, attributeFilter: ['style'] });

      fbBackdrop.addEventListener('click', function ()
      {
        fbModal.style.display = 'none';
      });

      var spFbContainer    = null;
      var spFbCurrentIndex = 0;
      var spFbTotalItems   = 0;
      var spFbItemName     = '';

      function spFbGetNameFromContainer(container, index)
      {
        if ( ! container) return '';
        var items = container.querySelectorAll('.fb-preview-item');
        var found = Array.prototype.find.call(items, function (item)
        {
          return parseInt(item.dataset.index, 10) === index;
        });
        if ( ! found) return '';
        var p = found.querySelector('p');
        return p ? p.textContent.trim() : '';
      }

      function spFbUpdateArrows()
      {
        var prevBtn = document.getElementById('fb-modal-prev');
        var nextBtn = document.getElementById('fb-modal-next');
        if (prevBtn) prevBtn.style.display = spFbCurrentIndex > 0 ? 'block' : 'none';
        if (nextBtn) nextBtn.style.display = spFbCurrentIndex < spFbTotalItems - 1 ? 'block' : 'none';

        if (spFbItemName && fbModalContent)
        {
          var h2 = fbModalContent.querySelector('h2');
          if (h2 && h2.textContent !== spFbItemName) h2.textContent = spFbItemName;
        }
      }

      document.addEventListener('click', function (e)
      {
        var previewItem = e.target.closest('.fb-preview-item');
        if (previewItem)
        {
          spFbContainer    = previewItem.closest('.fb-bundle-preview');
          spFbCurrentIndex = parseInt(previewItem.dataset.index, 10);
          if (isNaN(spFbCurrentIndex)) spFbCurrentIndex = 0;
          spFbTotalItems   = spFbContainer ? spFbContainer.querySelectorAll('.fb-preview-item').length : 0;
          var nameEl       = previewItem.querySelector('p');
          spFbItemName     = nameEl ? nameEl.textContent.trim() : '';
        }

        if (e.target.closest('#fb-modal-prev'))
        {
          spFbCurrentIndex = Math.max(0, spFbCurrentIndex - 1);
          spFbItemName     = spFbGetNameFromContainer(spFbContainer, spFbCurrentIndex);
        }

        if (e.target.closest('#fb-modal-next'))
        {
          spFbCurrentIndex = Math.min(spFbTotalItems - 1, spFbCurrentIndex + 1);
          spFbItemName     = spFbGetNameFromContainer(spFbContainer, spFbCurrentIndex);
        }
      });

      var fbModalContent = document.getElementById('fb-modal-content');
      if (fbModalContent)
      {
        new MutationObserver(spFbUpdateArrows)
          .observe(fbModalContent, { childList: true, subtree: true });
      }
    }

    // První produkt – otevřít a přepnout obrázek
    if ( ! isTouchDevice() )
    {
      items[0].classList.add('open');
      switchImage(items[0].dataset.img);
    }

    // ── Klik na produkt – toggle .open ──
    items.forEach(function (item)
    {
      item.addEventListener('click', function (e)
      {
        if (
          e.target.closest('.sp-inline-cart-btn') ||
          e.target.closest('.sp-bundle-select-btn') ||
          e.target.closest('.sp-detail-btn')      ||
          e.target.closest('select')              ||
          e.target.closest('input')               ||
          e.target.closest('.sp-hlidaci-btn')     ||
          e.target.closest('.fb-bundle-preview')  ||
          e.target.closest('#fb-modal')
        ) return;

        if (isTouchDevice()) return;

        const isOpen = item.classList.contains('open');

        items.forEach(function (i) { i.classList.remove('open'); });

        if ( ! isOpen)
        {
          item.classList.add('open');
          switchImage(item.dataset.img);
        }
      });
    });

    // ── Přidání do košíku ──
    document.addEventListener('click', function (e)
    {
      const btn = e.target.closest('.sp-inline-cart-btn');
      if ( ! btn ) return;

      // Zabránit dvojímu odeslání (bubbling nebo dva listenery)
      e.stopPropagation();

      // Ochrana proti double-click / double-submit
      if (btn.dataset.adding === '1') return;
      btn.dataset.adding = '1';
      setTimeout(function () { delete btn.dataset.adding; }, 3000);

      const item = btn.closest('.sp-product-item');
      if ( ! item ) return;

      const productId = item.dataset.id;

      // Vzít qty ze stejného panelu jako tlačítko (inline nebo mobil), ne z celého itemu
      const panel  = btn.closest('.sp-inline-actions') || btn.closest('.sp-mobile-panel') || item;
      const qtyInput = panel.querySelector('.sp-qty, .sp-inline-qty');
      const qty    = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

      const result = resolveInlineVariation(item, btn);  // ← přidáte , btn

      if (result.incomplete)
      {
        alert('Prosím vyberte variantu produktu.');
        return;
      }

      if (result.noMatch)
      {
        alert('Tato kombinace variant není dostupná.');
        return;
      }

      if (result.outOfStock)
      {
        alert('Tato varianta není skladem.');
        return;
      }

      addToCart(productId, result.variationId, qty, result.attrs, btn);
    });

    // ── Změna varianty – aktualizace ceny a obrázku ──
    document.addEventListener('change', function (e)
    {
      const select = e.target.closest('.sp-inline-variation-select');
      if ( ! select ) return;

      const item       = select.closest('.sp-product-item');
      const variations = JSON.parse(item.dataset.variations || '[]');
      const selects    = item.querySelectorAll('.sp-inline-variation-select');
      const selected   = {};

      selects.forEach(function (sel)
      {
        selected[sel.dataset.attribute] = sel.value;
      });

      const allChosen = Object.values(selected).every(function (v) { return v !== ''; });
      if ( ! allChosen ) return;

      const match = variations.find(function (v)
      {
        return Object.keys(selected).every(function (key)
        {
          return v.attributes[key] === '' || v.attributes[key] === selected[key];
        });
      });

      if ( ! match ) return;

      const inlinePrice = item.querySelector('.sp-inline-price');
      if (inlinePrice) inlinePrice.innerHTML = match.price_html;

      if (item.classList.contains('open'))
      {
        switchImage(match.image);
      }
    });

  });

})(jQuery);