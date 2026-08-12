/**
 * reLoopin Loyalty — Points Estimate badge (live qty / variation updates)
 */
(function ($) {
  'use strict';

  if (typeof reloopinPointsEstimate === 'undefined') {
    return;
  }

  var DEBOUNCE_MS = 300;

  function findForm($badge) {
    var $scope = $badge.closest('.product');
    var $form = $scope.length ? $scope.find('form.cart').first() : $();

    if (!$form.length) {
      $form = $badge.closest('form.cart');
    }
    if (!$form.length) {
      $form = $('form.variations_form').first();
    }
    if (!$form.length) {
      $form = $('form.cart').first();
    }

    return $form;
  }

  function getQty($form) {
    var qty = parseInt($form.find('input.qty').first().val(), 10);
    return isNaN(qty) || qty < 1 ? 1 : qty;
  }

  function getVariationId($badge) {
    return parseInt($badge.attr('data-variation-id'), 10) || 0;
  }

  function hideBadge($badge) {
    $badge.attr('hidden', 'hidden').removeAttr('data-points').text('');
    $badge.data('reloopinLastKey', null);
  }

  function fetchEstimate($badge, $form) {
    var productId = parseInt($badge.attr('data-product-id'), 10) || 0;
    if (!productId) {
      return;
    }

    var variationId = getVariationId($badge);
    if ($badge.attr('data-is-variable') === '1' && !variationId) {
      hideBadge($badge);
      return;
    }

    var qty = getQty($form);
    var key = variationId + ':' + qty;
    if ($badge.data('reloopinLastKey') === key) {
      return;
    }
    $badge.data('reloopinLastKey', key);

    $badge.addClass('is-loading');

    $.post(reloopinPointsEstimate.ajaxUrl, {
      action: reloopinPointsEstimate.action,
      nonce: reloopinPointsEstimate.nonce,
      product_id: productId,
      variation_id: variationId,
      qty: qty,
    })
      .done(function (response) {
        if (response && response.success && response.data) {
          $badge.attr('data-points', response.data.points_earned);
          $badge.text(response.data.text);
          $badge.removeAttr('hidden');
          return;
        }

        if (response && response.data && response.data.message === 'invalid_amount') {
          hideBadge($badge);
          return;
        }

        // Other failures keep the last good value; allow a retry.
        $badge.data('reloopinLastKey', null);
      })
      .fail(function () {
        $badge.data('reloopinLastKey', null);
      })
      .always(function () {
        $badge.removeClass('is-loading');
      });
  }

  function scheduleFetch($badge, $form) {
    clearTimeout($badge.data('reloopinTimer'));
    $badge.data(
      'reloopinTimer',
      setTimeout(function () {
        fetchEstimate($badge, $form);
      }, DEBOUNCE_MS)
    );
  }

  function bindBadge($badge) {
    var $form = findForm($badge);
    if (!$form.length) {
      return;
    }

    // Server-rendered value is already correct for the current selection.
    if ($badge.attr('data-points') !== undefined) {
      $badge.data('reloopinLastKey', getVariationId($badge) + ':' + getQty($form));
    }

    $form.on('input.reloopinEstimate change.reloopinEstimate', 'input.qty', function () {
      scheduleFetch($badge, $form);
    });

    $form.on('found_variation.reloopinEstimate show_variation.reloopinEstimate', function (_e, variation) {
      var vid = variation && variation.variation_id ? parseInt(variation.variation_id, 10) : 0;
      if (!vid) {
        return;
      }
      $badge.attr('data-variation-id', vid);
      scheduleFetch($badge, $form);
    });

    $form.on('hide_variation.reloopinEstimate reset_data.reloopinEstimate', function () {
      clearTimeout($badge.data('reloopinTimer'));
      $badge.attr('data-variation-id', 0);
      hideBadge($badge);
    });

    $form.on('change.reloopinEstimate', 'input[name="variation_id"]', function () {
      var vid = parseInt($(this).val(), 10) || 0;
      if (!vid || vid === getVariationId($badge)) {
        return;
      }
      $badge.attr('data-variation-id', vid);
      scheduleFetch($badge, $form);
    });

    // Default attributes may already select a variation before our events bind.
    if ($badge.attr('data-is-variable') === '1') {
      var initial = parseInt($form.find('input[name="variation_id"]').val(), 10) || 0;
      if (initial) {
        $badge.attr('data-variation-id', initial);
        scheduleFetch($badge, $form);
      }
    }
  }

  $(function () {
    $('.reloopin-points-estimate').each(function () {
      bindBadge($(this));
    });
  });
})(jQuery);
