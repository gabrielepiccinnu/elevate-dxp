/*
 * Elevate DXP — schema field → ExtJS form field / grid column mapping. GPL-3.0-or-later.
 */
elevatedxp.fields = elevatedxp.fields || {};

elevatedxp.fields.isStructured = function (type) {
    return ['json', 'keyvalue', 'tags', 'multiselect'].indexOf(type) !== -1;
};

elevatedxp.fields.optionStore = function (options) {
    return Ext.create('Ext.data.ArrayStore', {fields: ['value', 'label'], data: options || []});
};

elevatedxp.fields.toFormField = function (f, record) {
    var value = record ? record[f.name] : undefined;
    if (value === undefined && f['default'] !== undefined) {
        value = f['default'];
    }
    var base = {
        name: f.name,
        fieldLabel: t(f.label) + (f.required ? ' *' : ''),
        labelWidth: 170,
        width: '100%',
        readOnly: !!f.readOnly,
        allowBlank: !f.required || !!f.readOnly,
        afterBodyEl: f.help ? '<div class="elevatedxp-help">' + Ext.util.Format.htmlEncode(f.help) + '</div>' : undefined,
        cls: f.help ? 'elevatedxp-has-help' : undefined,
        dxpType: f.type
    };

    switch (f.type) {
        case 'textarea':
            return Ext.apply(base, {xtype: 'textareafield', height: 90, value: value});
        case 'code':
            return Ext.apply(base, {xtype: 'textareafield', height: 220, cls: 'elevatedxp-code', value: value});
        case 'password':
            return Ext.apply(base, {xtype: 'textfield', inputType: 'password', value: value});
        case 'number':
            return Ext.apply(base, {xtype: 'numberfield', value: value, decimalPrecision: 6, width: 400});
        case 'bool':
            return Ext.apply(base, {xtype: 'checkboxfield', checked: !!value, inputValue: true, uncheckedValue: false});
        case 'date':
            return Ext.apply(base, {xtype: 'datefield', format: 'Y-m-d', submitFormat: 'Y-m-d', value: value, width: 400});
        case 'datetime':
            return Ext.apply(base, {xtype: 'textfield', emptyText: 'YYYY-MM-DD HH:MM:SS', value: value, width: 420});
        case 'select':
            return Ext.apply(base, {
                xtype: 'combo', store: elevatedxp.fields.optionStore(f.options), valueField: 'value', displayField: 'label',
                queryMode: 'local', editable: false, forceSelection: true, value: value, width: 500
            });
        case 'multiselect':
            return Ext.apply(base, {
                xtype: 'tagfield', store: elevatedxp.fields.optionStore(f.options), valueField: 'value', displayField: 'label',
                queryMode: 'local', filterPickList: true, value: value || []
            });
        case 'tags':
            return Ext.apply(base, {
                xtype: 'textfield', emptyText: 'comma, separated, values',
                value: Ext.isArray(value) ? value.join(', ') : (value || '')
            });
        case 'keyvalue':
            var lines = [];
            if (value && typeof value === 'object') {
                Ext.Object.each(value, function (k, v) {
                    lines.push(k + ' = ' + (typeof v === 'object' ? Ext.encode(v) : v));
                });
            }
            return Ext.apply(base, {
                xtype: 'textareafield', height: 120, cls: 'elevatedxp-code', value: lines.join('\n'),
                emptyText: 'key = value (one per line)'
            });
        case 'json':
            return Ext.apply(base, {
                xtype: 'textareafield', height: 220, cls: 'elevatedxp-code',
                value: value === undefined || value === null ? '' : (typeof value === 'string' ? value : JSON.stringify(value, null, 2))
            });
        case 'element':
            var field = Ext.create('Ext.form.field.Text', Ext.apply(base, {value: value, flex: 1, fieldLabel: base.fieldLabel}));
            return {
                xtype: 'fieldcontainer', layout: 'hbox', width: '100%', dxpField: field, items: [field, {
                    xtype: 'button', iconCls: 'opendxp_icon_search', margin: '0 0 0 5', handler: function () {
                        opendxp.helpers.itemselector(false, function (item) {
                            field.setValue(item.fullpath);
                        }, {type: [f.elementType || 'object']});
                    }
                }]
            };
        default:
            return Ext.apply(base, {xtype: 'textfield', value: value});
    }
};

/**
 * Converts the raw form field value to what the server expects for the field type.
 */
elevatedxp.fields.readValue = function (f, cmp) {
    var v = cmp.getValue();
    switch (f.type) {
        case 'bool':
            return !!v;
        case 'date':
            return v ? Ext.Date.format(v, 'Y-m-d') : null;
        case 'number':
            return v === null || v === '' ? null : v;
        case 'tags':
            return (v || '').split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
        case 'keyvalue':
            var obj = {};
            (v || '').split('\n').forEach(function (line) {
                var idx = line.indexOf('=');
                if (idx > 0) {
                    obj[line.substr(0, idx).trim()] = line.substr(idx + 1).trim();
                }
            });
            return obj;
        case 'json':
            if (!v || !v.trim()) return null;
            try {
                return JSON.parse(v);
            } catch (e) {
                throw new Error(t(f.label) + ': invalid JSON');
            }
        default:
            return v;
    }
};

elevatedxp.fields.buildForm = function (fields, record) {
    var items = [];
    var map = {};
    (fields || []).forEach(function (f) {
        if (f.hidden) return;
        var cfg = elevatedxp.fields.toFormField(f, record || {});
        var cmp = Ext.create(cfg.xtype === 'fieldcontainer' ? 'Ext.form.FieldContainer' : 'widget.' + cfg.xtype, cfg);
        map[f.name] = {field: f, cmp: cmp.dxpField || cmp};
        items.push(cmp);
    });
    var form = Ext.create('Ext.form.Panel', {
        bodyStyle: 'padding:10px', scrollable: true, border: false, defaults: {margin: '0 0 8 0'}, items: items
    });
    form.dxpCollect = function () {
        var out = {};
        Ext.Object.each(map, function (name, entry) {
            out[name] = elevatedxp.fields.readValue(entry.field, entry.cmp);
        });
        return out;
    };
    return form;
};

elevatedxp.fields.toColumn = function (f) {
    var col = {text: t(f.label), dataIndex: f.name, sortable: true};
    if (f.width) col.width = f.width; else col.flex = f.flex || 1;
    if (f.type === 'bool') {
        col.renderer = function (v) { return v ? '✔' : ''; };
    } else if (f.type === 'select') {
        var labels = {};
        (f.options || []).forEach(function (o) { labels[o[0]] = o[1]; });
        col.renderer = function (v) { return Ext.util.Format.htmlEncode(labels[v] !== undefined ? labels[v] : v); };
    } else if (elevatedxp.fields.isStructured(f.type)) {
        col.sortable = false;
        col.renderer = function (v) { return Ext.util.Format.htmlEncode(Ext.isString(v) ? v : JSON.stringify(v)); };
    } else if (f.type === 'number' && f.format) {
        col.renderer = Ext.util.Format.numberRenderer(f.format);
        col.align = 'right';
    } else {
        col.renderer = function (v) { return Ext.util.Format.htmlEncode(v); };
    }
    return col;
};
