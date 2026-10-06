/*
 * Elevate DXP — runs a declared resource action and renders its result. GPL-3.0-or-later.
 */
elevatedxp.actions = elevatedxp.actions || {};

elevatedxp.actions.run = function (key, action, recordId, onDone) {
    var execute = function (params) {
        var mask = Ext.getBody();
        mask.mask(t('please_wait'));
        elevatedxp.request(elevatedxp.url(key, 'action/' + action.name), 'POST', {id: recordId, params: params || {}}, function (res) {
            mask.unmask();
            elevatedxp.actions.showResult(res, action);
            if (res.reload && onDone) onDone(res);
        }, function () {
            mask.unmask();
        });
    };

    var withParams = function () {
        if (!action.params || !action.params.length) {
            execute({});
            return;
        }
        var form = elevatedxp.fields.buildForm(action.params, {});
        var win = Ext.create('Ext.window.Window', {
            title: t(action.label), modal: true, width: 640, maxHeight: 600, layout: 'fit', items: [form],
            buttons: [{
                text: t(action.label), iconCls: action.iconCls, handler: function () {
                    var values;
                    try {
                        values = form.dxpCollect();
                    } catch (e) {
                        elevatedxp.notifyError(e.message);
                        return;
                    }
                    win.close();
                    execute(values);
                }
            }]
        });
        win.show();
    };

    if (action.confirm) {
        Ext.Msg.confirm(t(action.label), t(action.confirm), function (btn) {
            if (btn === 'yes') withParams();
        });
    } else {
        withParams();
    }
};

elevatedxp.actions.showResult = function (res, action) {
    var title = res.title || t(action.label);
    if (res.url) {
        window.open(res.url, '_blank');
    }
    if (res.rows) {
        elevatedxp.actions.showTable(title, res.rows, res.columns);
    } else if (res.text !== undefined) {
        Ext.create('Ext.window.Window', {
            title: title, width: 900, height: 600, layout: 'fit', modal: true,
            items: [{xtype: 'textareafield', readOnly: true, cls: 'elevatedxp-code', value: res.text}]
        }).show();
    } else if (res.html !== undefined) {
        Ext.create('Ext.window.Window', {
            title: title, width: 900, height: 600, layout: 'fit', modal: true, scrollable: true,
            items: [{xtype: 'panel', bodyStyle: 'padding:10px', scrollable: true, html: res.html}]
        }).show();
    }
    if (res.message) {
        opendxp.helpers.showNotification(t('success'), res.message, 'success');
    }
};

elevatedxp.actions.showTable = function (title, rows, columns) {
    columns = columns && columns.length ? columns : (rows.length ? Object.keys(rows[0]) : []);
    var store = Ext.create('Ext.data.Store', {fields: columns, data: rows});
    Ext.create('Ext.window.Window', {
        title: title, width: 1000, height: 600, layout: 'fit', modal: true,
        items: [{
            xtype: 'grid', store: store, columnLines: true,
            columns: columns.map(function (c) {
                return {
                    text: c, dataIndex: c, flex: 1, renderer: function (v) {
                        return Ext.util.Format.htmlEncode(v !== null && typeof v === 'object' ? JSON.stringify(v) : v);
                    }
                };
            })
        }]
    }).show();
};
