define(['jquery'], function($) {
    $.mage = $.mage || {};

    $.fn.mage = function () {
        console.profile('mage');
        return this;
    };

    $.extend($.mage, {
        init: function () { return this; },
        redirect: function() {},
        isValidSelector: function() { return true; }
    });
});
