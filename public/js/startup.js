/*
 * Elevate DXP — main navigation entry. Feature list is injected server-side into
 * opendxp.settings.elevatedxp (AdminSettingsListener), filtered by user permissions.
 */

elevatedxp.startup = Class.create({
    initialize: function () {
        document.addEventListener(opendxp.events.preMenuBuild, this.preMenuBuild.bind(this));
    },

    preMenuBuild: function (e) {
        var features = (opendxp.settings.elevatedxp || {}).features || [];
        if (!features.length) {
            return;
        }
        var groups = {};
        features.forEach(function (f) {
            (groups[f.group] = groups[f.group] || []).push(f);
        });

        var items = Object.keys(groups).sort().map(function (group) {
            return {
                text: t(group),
                iconCls: 'opendxp_nav_icon_elevatedxp_group',
                hideOnClick: false,
                menu: {
                    cls: 'opendxp_navigation_flyout',
                    shadow: false,
                    items: groups[group].map(function (f) {
                        return {
                            text: t(f.label),
                            iconCls: f.iconCls,
                            itemId: 'elevatedxp_menu_' + f.key,
                            handler: function () { elevatedxp.open(f); }
                        };
                    })
                }
            };
        });

        e.detail.menu.elevatedxp = {
            label: 'Elevate DXP',
            iconCls: 'opendxp_main_nav_icon_elevatedxp',
            priority: 55,
            items: items,
            shadow: false,
            cls: 'opendxp_navigation_flyout'
        };
    }
});

elevatedxp.startupInstance = new elevatedxp.startup();
