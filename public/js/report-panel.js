/*
 * Elevate DXP — filterable report table with optional bar chart. GPL-3.0-or-later.
 */

elevatedxp.ReportPanel = Class.create(elevatedxp.AbstractTab, {

    buildContent: function () {
        var schema = this.schema;
        var fields = schema.fields || [];

        this.store = Ext.create('Ext.data.Store', {
            fields: fields.map(function (f) { return f.name; }),
            proxy: {
                type: 'ajax',
                url: elevatedxp.url(this.key, 'list'),
                reader: {type: 'json', rootProperty: 'data', totalProperty: 'total'}
            },
            autoLoad: !(schema.filters || []).some(function (f) { return f.required; })
        });

        this.filterForm = null;
        var items = [];
        if ((schema.filters || []).length) {
            this.filterForm = elevatedxp.fields.buildForm(schema.filters, {});
            this.filterForm.setLayout({type: 'hbox', align: 'stretch'});
            this.filterForm.items.each(function (cmp) {
                cmp.setWidth(320);
                cmp.margin = '0 15 0 0';
            });
            this.filterForm.setHeight(55);
            items.push(this.filterForm);
        }

        var tbar = [{text: t('elevatedxp_run_report'), iconCls: 'opendxp_icon_play', handler: this.load.bind(this)}];
        (schema.actions || []).forEach(function (action) {
            tbar.push({
                text: t(action.label), iconCls: action.iconCls, handler: function () {
                    elevatedxp.actions.run(this.key, action, null, this.load.bind(this));
                }.bind(this)
            });
        }.bind(this));

        this.grid = Ext.create('Ext.grid.Panel', {
            store: this.store,
            flex: 1,
            columnLines: true,
            columns: fields.filter(function (f) { return f.grid !== false; }).map(elevatedxp.fields.toColumn)
        });
        items.push(this.grid);

        if (schema.chart && Ext.ClassManager.get('Ext.chart.CartesianChart')) {
            this.chart = Ext.create('Ext.chart.CartesianChart', {
                height: 260,
                store: this.store,
                legend: {docked: 'bottom'},
                axes: [
                    {type: 'numeric', position: 'left', fields: schema.chart.y, grid: true, minimum: 0},
                    {type: 'category', position: 'bottom', fields: [schema.chart.x]}
                ],
                series: [{type: 'bar', xField: schema.chart.x, yField: schema.chart.y, stacked: false,
                    tooltip: {trackMouse: true, renderer: function (tooltip, record, item) {
                        tooltip.setHtml(record.get(schema.chart.x) + ': ' + record.get(item.field));
                    }}}]
            });
            items.push(this.chart);
        }

        return {xtype: 'panel', layout: {type: 'vbox', align: 'stretch'}, bodyStyle: 'padding:8px', tbar: tbar, items: items};
    },

    load: function () {
        var filters = {};
        if (this.filterForm) {
            try {
                filters = this.filterForm.dxpCollect();
            } catch (e) {
                elevatedxp.notifyError(e.message);
                return;
            }
        }
        this.store.getProxy().setExtraParam('filters', Ext.encode(filters));
        this.store.load();
    }
});
