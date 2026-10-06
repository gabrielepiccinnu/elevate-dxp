/*
 * Elevate DXP — shared admin helpers. GPL-3.0-or-later.
 */
window.elevatedxp = window.elevatedxp || {};

elevatedxp.baseUrl = '/admin/elevate-dxp';

elevatedxp.url = function (key, op) {
    return elevatedxp.baseUrl + '/r/' + encodeURIComponent(key) + '/' + op;
};

elevatedxp.resolve = function (path) {
    return (path || '').split('.').reduce(function (obj, part) {
        return obj ? obj[part] : undefined;
    }, window);
};

elevatedxp.notifyError = function (message) {
    opendxp.helpers.showNotification(t('error'), message || 'Error', 'error');
};

/**
 * JSON request helper. Calls cb(data) only when the server answered success !== false.
 */
elevatedxp.request = function (url, method, payload, cb, failCb) {
    var cfg = {
        url: url,
        method: method,
        success: function (response) {
            var data = {};
            try {
                data = Ext.decode(response.responseText);
            } catch (e) {
                elevatedxp.notifyError('Invalid server response');
                return;
            }
            if (data.success === false) {
                elevatedxp.notifyError(data.message);
                if (failCb) failCb(data);
                return;
            }
            if (cb) cb(data);
        },
        failure: function (response) {
            var msg = response.statusText;
            try {
                msg = Ext.decode(response.responseText).message || msg;
            } catch (e) {
            }
            elevatedxp.notifyError(msg);
            if (failCb) failCb({success: false, message: msg});
        }
    };
    if (method === 'GET') {
        cfg.params = payload || {};
    } else {
        cfg.jsonData = payload || {};
    }
    Ext.Ajax.request(cfg);
};

/**
 * Opens (or re-activates) the tab for a feature returned by /features.
 */
elevatedxp.open = function (feature) {
    var gmKey = 'elevatedxp_' + feature.key;
    try {
        opendxp.globalmanager.get(gmKey).activate();
        return;
    } catch (e) {
    }

    elevatedxp.request(elevatedxp.url(feature.key, 'schema'), 'GET', {}, function (res) {
        var schema = res.schema || {};
        var panelClass;
        if (schema.panel === 'custom' && schema.jsClass) {
            panelClass = elevatedxp.resolve(schema.jsClass);
        } else if (schema.panel === 'report') {
            panelClass = elevatedxp.ReportPanel;
        } else {
            panelClass = elevatedxp.CrudPanel;
        }
        if (!panelClass) {
            elevatedxp.notifyError('Panel class not found: ' + schema.jsClass);
            return;
        }
        opendxp.globalmanager.add(gmKey, new panelClass({
            key: feature.key,
            label: res.label || feature.label,
            iconCls: res.iconCls || feature.iconCls,
            schema: schema,
            gmKey: gmKey
        }));
    });
};

/**
 * Base tab wrapper used by all panels.
 */
elevatedxp.AbstractTab = Class.create({
    initialize: function (config) {
        this.config = config;
        this.schema = config.schema;
        this.key = config.key;
        this.panelId = 'elevatedxp_panel_' + config.key;
        this.getTabPanel();
    },

    activate: function () {
        Ext.getCmp('opendxp_panel_tabs').setActiveItem(this.panelId);
    },

    getTabPanel: function () {
        if (!this.panel) {
            this.panel = new Ext.Panel({
                id: this.panelId,
                title: t(this.config.label),
                iconCls: this.config.iconCls,
                border: false,
                layout: 'fit',
                closable: true,
                items: [this.buildContent()]
            });
            var tabPanel = Ext.getCmp('opendxp_panel_tabs');
            tabPanel.add(this.panel);
            tabPanel.setActiveItem(this.panelId);
            this.panel.on('destroy', function () {
                opendxp.globalmanager.remove(this.config.gmKey);
            }.bind(this));
            opendxp.layout.refresh();
        }
        return this.panel;
    },

    buildContent: function () {
        return {xtype: 'panel', html: ''};
    }
});
