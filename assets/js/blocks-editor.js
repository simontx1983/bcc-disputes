/**
 * BCC Disputes – Gutenberg Block Editor Registration
 *
 * All blocks are dynamic (server-side rendered), so the editor
 * shows a <ServerSideRender /> preview with an InspectorControls panel.
 */
(function (wp) {
    var registerBlockType  = wp.blocks.registerBlockType;
    var createElement      = wp.element.createElement;
    var Fragment           = wp.element.Fragment;
    var InspectorControls  = wp.blockEditor.InspectorControls;
    var PanelBody          = wp.components.PanelBody;
    var TextControl        = wp.components.TextControl;
    var ServerSideRender   = wp.serverSideRender;
    var useBlockProps       = wp.blockEditor.useBlockProps;

    /* =========================================================
       1. Dispute Form
       ========================================================= */
    registerBlockType('bcc-disputes/dispute-form', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'Page ID',
                            help: 'Leave 0 to auto-detect from the current page.',
                            value: String(attrs.pageId || ''),
                            onChange: function (v) { props.setAttributes({ pageId: parseInt(v, 10) || 0 }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-disputes/dispute-form',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       2. Dispute Panel Queue
       ========================================================= */
    registerBlockType('bcc-disputes/dispute-queue', {
        edit: function (props) {
            var blockProps = useBlockProps();
            return createElement('div', blockProps,
                createElement(ServerSideRender, {
                    block: 'bcc-disputes/dispute-queue',
                    attributes: props.attributes,
                })
            );
        },
        save: function () { return null; },
    });

    /* =========================================================
       3. Report User Button
       ========================================================= */
    registerBlockType('bcc-disputes/report-button', {
        edit: function (props) {
            var attrs = props.attributes;
            var blockProps = useBlockProps();
            return createElement(Fragment, null,
                createElement(InspectorControls, null,
                    createElement(PanelBody, { title: 'Settings', initialOpen: true },
                        createElement(TextControl, {
                            label: 'User ID',
                            help: 'The ID of the user to report. Required.',
                            value: String(attrs.userId || ''),
                            onChange: function (v) { props.setAttributes({ userId: parseInt(v, 10) || 0 }); },
                        })
                    )
                ),
                createElement('div', blockProps,
                    createElement(ServerSideRender, {
                        block: 'bcc-disputes/report-button',
                        attributes: attrs,
                    })
                )
            );
        },
        save: function () { return null; },
    });

})(window.wp);
