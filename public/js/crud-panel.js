/*
 * Elevate DXP — generic grid + edit form panel driven by the resource schema. GPL-3.0-or-later.
 */

elevatedxp.CrudPanel = Class.create(elevatedxp.AbstractTab, {

    buildContent: function () {
        var schema = this.schema;
        var idProperty = schema.idProperty || 'id';
        var fields = schema.fields || [];
        var gridFields = fields.filter(function (f) { return f.grid !== false && !f.hidden; });

        this.store = Ext.create('Ext.data.Store', {
            pageSize: 50,
            remoteSort: true,
            autoLoad: true,
            fields: fields.map(function (f) { return f.name; }).concat([idProperty]),
            proxy: {
                type: 'ajax',
                url: elevatedxp.url(this.key, 'list'),
                reader: {type: 'json', rootProperty: 'data', totalProperty: 'total'},
                extraParams: {}
            }
        });

        var tbar = [];
        if (schema.canCreate !== false && schema.readOnly !== true) {
            tbar.push({text: t('add'), iconCls: 'opendxp_icon_add', handler: this.edit.bind(this, null)});
        }
        if (schema.canEdit !== false && schema.readOnly !== true) {
            tbar.push({text: t('edit'), iconCls: 'opendxp_icon_edit', handler: this.editSelected.bind(this)});
        }
        if (schema.canDelete !== false && schema.readOnly !== true) {
            tbar.push({text: t('delete'), iconCls: 'opendxp_icon_delete', handler: this.deleteSelected.bind(this)});
        }
        (schema.actions || []).forEach(function (action) {
            tbar.push({
                text: t(action.label), iconCls: action.iconCls, handler: function () {
                    var rec = this.selected();
                    if (action.scope === 'record' && !rec) {
                        elevatedxp.notifyError(t('elevatedxp_select_record'));
                        return;
                    }
                    elevatedxp.actions.run(this.key, action, rec ? rec.get(idProperty) : null, function () {
                        this.store.reload();
                    }.bind(this));
                }.bind(this)
            });
        }.bind(this));
        tbar.push('->');
        (schema.filters || []).forEach(function (f) {
            var cfg = elevatedxp.fields.toFormField(Ext.apply({}, {required: false, help: null}, f), {});
            cfg.fieldLabel = null;
            cfg.emptyText = t(f.label);
            cfg.width = 160;
            if (f.type === 'select') {
                cfg.editable = false;
                cfg.forceSelection = false;
                cfg.triggers = {clear: {cls: 'x-form-clear-trigger', handler: function (field) { field.clearValue(); }}};
            }
            cfg.listeners = {
                change: {
                    buffer: 400, fn: function (field) {
                        var filters = Ext.decode(this.store.getProxy().getExtraParams().filters || '{}');
                        filters[f.name] = field.getValue();
                        this.store.getProxy().setExtraParam('filters', Ext.encode(filters));
                        this.store.loadPage(1);
                    }.bind(this)
                }
            };
            tbar.push(cfg);
        }.bind(this));
        tbar.push({
            xtype: 'textfield', emptyText: t('search'), width: 220, enableKeyEvents: true,
            listeners: {
                keyup: {
                    buffer: 400, fn: function (field) {
                        this.store.getProxy().setExtraParam('q', field.getValue());
                        this.store.loadPage(1);
                    }.bind(this)
                }
            }
        });
        tbar.push({iconCls: 'opendxp_icon_reload', handler: function () { this.store.reload(); }.bind(this)});

        var columns = [{text: 'ID', dataIndex: idProperty, width: 70}].concat(
            gridFields.filter(function (f) { return f.name !== idProperty; }).map(elevatedxp.fields.toColumn)
        );

        this.grid = Ext.create('Ext.grid.Panel', {
            store: this.store,
            columns: columns,
            columnLines: true,
            stripeRows: true,
            tbar: tbar,
            bbar: opendxp.helpers.grid.buildDefaultPagingToolbar(this.store),
            listeners: {
                rowdblclick: function (grid, record) {
                    if (schema.canEdit !== false && schema.readOnly !== true) {
                        this.edit(record.get(idProperty));
                    } else if (schema.detail !== false) {
                        this.edit(record.get(idProperty), true);
                    }
                }.bind(this)
            }
        });

        return this.grid;
    },

    selected: function () {
        var sel = this.grid.getSelectionModel().getSelection();
        return sel.length ? sel[0] : null;
    },

    editSelected: function () {
        var rec = this.selected();
        if (!rec) {
            elevatedxp.notifyError(t('elevatedxp_select_record'));
            return;
        }
        this.edit(rec.get(this.schema.idProperty || 'id'));
    },

    deleteSelected: function () {
        var rec = this.selected();
        if (!rec) {
            elevatedxp.notifyError(t('elevatedxp_select_record'));
            return;
        }
        var id = rec.get(this.schema.idProperty || 'id');
        Ext.Msg.confirm(t('delete'), t('are_you_sure'), function (btn) {
            if (btn !== 'yes') return;
            elevatedxp.request(elevatedxp.url(this.key, 'delete'), 'POST', {id: id}, function () {
                this.store.reload();
            }.bind(this));
        }.bind(this));
    },

    edit: function (id, readOnly) {
        var open = function (record) {
            var fields = (this.schema.fields || []).map(function (f) {
                return readOnly ? Ext.apply({}, {readOnly: true}, f) : f;
            });
            var form = elevatedxp.fields.buildForm(fields, record);
            var win = Ext.create('Ext.window.Window', {
                title: t(this.config.label) + (id ? ' #' + id : ''),
                modal: true, width: 820, height: Math.min(window.innerHeight - 80, 720), layout: 'fit', items: [form],
                buttons: readOnly ? [] : [{
                    text: t('save'), iconCls: 'opendxp_icon_save', handler: function () {
                        var data;
                        try {
                            data = form.dxpCollect();
                        } catch (e) {
                            elevatedxp.notifyError(e.message);
                            return;
                        }
                        if (id) data[this.schema.idProperty || 'id'] = id;
                        elevatedxp.request(elevatedxp.url(this.key, 'save'), 'POST', {data: data}, function () {
                            opendxp.helpers.showNotification(t('success'), t('saved_successfully'), 'success');
                            win.close();
                            this.store.reload();
                        }.bind(this));
                    }.bind(this)
                }]
            });
            win.show();
        }.bind(this);

        if (!id) {
            open({});
            return;
        }
        elevatedxp.request(elevatedxp.url(this.key, 'get'), 'GET', {id: id}, function (res) {
            open(res.data || {});
        });
    }
});
