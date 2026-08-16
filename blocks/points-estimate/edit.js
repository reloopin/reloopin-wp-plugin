(function (wp) {
  var registerBlockType = wp.blocks.registerBlockType;
  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var PanelBody = wp.components.PanelBody;
  var TextControl = wp.components.TextControl;
  var __ = wp.i18n.__;

  registerBlockType('reloopin/points-estimate', {
    edit: function (props) {
      var productId = props.attributes.productId || 0;

      return el(
        Fragment,
        null,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: __('Points Estimate', 'reloopin-loyalty'), initialOpen: true },
            el(TextControl, {
              label: __('Product ID (optional)', 'reloopin-loyalty'),
              help: __(
                'Leave empty to use the current product on single product templates.',
                'reloopin-loyalty'
              ),
              type: 'number',
              value: productId > 0 ? String(productId) : '',
              onChange: function (value) {
                var parsed = parseInt(value, 10);
                props.setAttributes({
                  productId: isNaN(parsed) || parsed < 1 ? 0 : parsed,
                });
              },
            })
          )
        ),
        el(
          'div',
          {
            className: 'reloopin-points-estimate reloopin-points-estimate--editor',
          },
          __('Earn — points on this purchase', 'reloopin-loyalty'),
          productId > 0
            ? el(
                'span',
                { style: { display: 'block', fontWeight: '400', fontSize: '0.85em', marginTop: '0.25rem' } },
                __('Product ID: ', 'reloopin-loyalty') + productId
              )
            : null
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
