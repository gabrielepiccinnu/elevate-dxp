/*
 * Elevate DXP — editors for the extra targeting conditions/actions shown in
 * Marketing → Personalization → Targeting Rules. GPL-3.0-or-later.
 */
(function () {
    if (!opendxp.bundle || !opendxp.bundle.personalization || !opendxp.bundle.personalization.settings) {
        return;
    }
    var settings = opendxp.bundle.personalization.settings;
    var modes = [['equals', 'equals'], ['contains', 'contains'], ['starts_with', 'starts with'], ['regex', 'regex'], ['exists', 'exists']];

    var form = function (self, panel, data, type, items) {
        var id = Ext.id();
        return new Ext.form.FormPanel({
            id: id,
            forceLayout: true,
            style: 'margin: 10px 0 0 0',
            bodyStyle: 'padding: 10px 30px 10px 30px; min-height:40px;',
            tbar: settings.conditions.getTopBar(self, id, panel, data),
            items: items.concat([{xtype: 'hidden', name: 'type', value: type}])
        });
    };
    var modeCombo = function (data) {
        return {xtype: 'combo', fieldLabel: 'Match', name: 'mode', store: modes, value: data.mode || 'equals',
            editable: false, forceSelection: true, triggerAction: 'all', queryMode: 'local', width: 350};
    };
    var condition = function (name, icon, builder) {
        return Class.create(settings.condition.abstract, {
            getName: function () { return name; },
            getIconCls: function () { return icon; },
            matchesScope: function (scope) { return in_array(scope, ['targeting_rule', 'targeting_group_entry_condition']); },
            getPanel: builder
        });
    };

    settings.conditions.register('edxp_experiment_variant', condition('Experiment variant (Elevate DXP)', 'elevatedxp_icon_experiment', function (panel, data) {
        return form(this, panel, data, 'edxp_experiment_variant', [
            {xtype: 'textfield', fieldLabel: 'Experiment key', name: 'experiment', value: data.experiment, width: 400},
            {xtype: 'textfield', fieldLabel: 'Variant (empty = any)', name: 'variant', value: data.variant, width: 400}
        ]);
    }));

    settings.conditions.register('edxp_query_param', condition('Query parameter (Elevate DXP)', 'opendxp_icon_url', function (panel, data) {
        return form(this, panel, data, 'edxp_query_param', [
            {xtype: 'textfield', fieldLabel: 'Parameter', name: 'name', value: data.name, width: 400},
            modeCombo(data),
            {xtype: 'textfield', fieldLabel: 'Value', name: 'value', value: data.value, width: 400}
        ]);
    }));

    settings.conditions.register('edxp_utm', condition('Campaign / UTM (Elevate DXP)', 'opendxp_icon_marketing', function (panel, data) {
        return form(this, panel, data, 'edxp_utm', [
            {xtype: 'combo', fieldLabel: 'Parameter', name: 'parameter', value: data.parameter || 'utm_campaign', editable: false,
                store: ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], queryMode: 'local', width: 400},
            modeCombo(data),
            {xtype: 'textfield', fieldLabel: 'Value', name: 'value', value: data.value, width: 400},
            {xtype: 'combo', fieldLabel: 'Touch', name: 'touch', value: data.touch || 'last', editable: false,
                store: [['last', 'last touch (current or latest campaign)'], ['first', 'first touch']], queryMode: 'local', width: 400}
        ]);
    }));

    settings.conditions.register('edxp_cookie', condition('Cookie (Elevate DXP)', 'opendxp_icon_key', function (panel, data) {
        return form(this, panel, data, 'edxp_cookie', [
            {xtype: 'textfield', fieldLabel: 'Cookie name', name: 'name', value: data.name, width: 400},
            modeCombo(data),
            {xtype: 'textfield', fieldLabel: 'Value', name: 'value', value: data.value, width: 400}
        ]);
    }));

    settings.conditions.register('edxp_time_window', condition('Time window (Elevate DXP)', 'opendxp_icon_time', function (panel, data) {
        return form(this, panel, data, 'edxp_time_window', [
            {xtype: 'textfield', fieldLabel: 'Weekdays (1=Mon … 7=Sun)', name: 'days', value: Ext.isArray(data.days) ? data.days.join(',') : data.days, emptyText: '1,2,3,4,5', width: 400},
            {xtype: 'textfield', fieldLabel: 'From (HH:MM)', name: 'from', value: data.from, width: 250},
            {xtype: 'textfield', fieldLabel: 'To (HH:MM)', name: 'to', value: data.to, width: 250},
            {xtype: 'textfield', fieldLabel: 'Timezone', name: 'timezone', value: data.timezone || 'Europe/Rome', width: 400}
        ]);
    }));

    settings.conditions.register('edxp_returning_visitor', condition('Returning visitor (Elevate DXP)', 'opendxp_icon_user', function (panel, data) {
        return form(this, panel, data, 'edxp_returning_visitor', [
            {xtype: 'numberfield', fieldLabel: 'Minimum sessions', name: 'minSessions', value: data.minSessions || 2, minValue: 1, width: 250},
            {xtype: 'checkbox', fieldLabel: 'Inverse (new visitors)', name: 'inverse', checked: !!data.inverse, inputValue: true, uncheckedValue: false}
        ]);
    }));

    if (settings.actions && settings.action) {
        settings.actions.register('edxp_set_variant', Class.create(settings.action.abstract, {
            getName: function () { return 'Set experiment variant (Elevate DXP)'; },
            getPanel: function (panel, data) {
                var id = Ext.id();
                return new Ext.form.FormPanel({
                    id: id,
                    forceLayout: true,
                    border: true,
                    style: 'margin: 10px 0 0 0',
                    bodyStyle: 'padding: 10px 30px 10px 30px; min-height:40px;',
                    tbar: settings.actions.getTopBar(this, id, panel),
                    items: [
                        {xtype: 'textfield', fieldLabel: 'Experiment key', name: 'experiment', value: data.experiment, width: 400},
                        {xtype: 'textfield', fieldLabel: 'Variant', name: 'variant', value: data.variant, width: 400},
                        {xtype: 'hidden', name: 'type', value: 'edxp_set_variant'}
                    ]
                });
            }
        }));
    }
})();
