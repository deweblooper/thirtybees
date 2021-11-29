/**
 * 2007-2016 PrestaShop
 *
 * thirty bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017-2018 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.thirtybees.com for more information.
 *
 *  @author    thirty bees <contact@thirtybees.com>
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2017-2018 thirty bees
 *  @copyright 2007-2016 PrestaShop SA
 *  @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  PrestaShop is an internationally registered trademark & property of PrestaShop SA
 */

/* global window, $, moment, autorefresh_notifications, order_number_msg, customer_name_msg, from_msg, full_language_code */

$(document).ready(function () {
  // set up notification click handler
  $('.notifs').click(function () {
    var parent = $(this).parent();
    var idWrapper = parent.attr('id');
    $.post(
      'ajax.php',
      {
        markNotificationsRead: 1,
        type: parent.data('type'),
        lastId: parent.data('lastId'),
      },
      function (data) {
        if (data) {
          $('#' + idWrapper + '_value').html(0);
          $('#' + idWrapper + '_number_wrapper').hide();
        }
      }
    );
  });

  // update notifications once, as soon as possible
  setTimeout(updateNotifications, 0);

  // set up refresh interval
  if (autorefresh_notifications) {
    setInterval(updateNotifications, 120000);
  }
});

function updateNotifications() {
  $.ajax({
    type: 'POST',
    headers: { 'cache-control': 'no-cache' },
    url: 'ajax.php?rand=' + new Date().getTime(),
    async: true,
    cache: false,
    dataType: 'json',
    data: {
      getNotifications: '1'
    },
    success: function (json) {
      if (json) {
        // Set moment language
        moment.lang(full_language_code);

        for (var i = 0; i < json.length; i++) {
          var record = json[i];
          var type = record.type;
          var total = record.total
          var lastId = record.lastId;
          $('#'+type+'_notif').data('lastId', lastId);
          if (total > 0) {
            var defaultRenderer = record.renderer;
            var results = record.results;
            var html = '';
            for (var j = 0; j < results.length; j++) {
              var renderer = results[j].renderer || defaultRenderer;
              html += executeFunctionByName(renderer, [results[j]]);
            }
            $('#'+type+'_notif_list').empty().append(html);
            $('#'+type+'_notif_value').text(total);
            $('#'+type+'_notif_number_wrapper').removeClass('hide');
          } else {
            $('#'+type+'_notif_number_wrapper').addClass('hide');
          }
        }
      }
    }
  });
}

/* renderers for default notification types */

function renderOrderNotification(notification) {
  var html = '';
  html += "<a id='notification_order"+notification.id+"' href='"+notification.link+"'>";
  html += "<p>" + order_number_msg + "&nbsp;<strong>#" + parseInt(notification.id, 10) + "</strong></p>";
  html += "<p class='pull-right'>" + total_msg + "&nbsp;<span class='total badge badge-success'>" + notification.total + "</span></p>";
  html += "<p>" + from_msg + "&nbsp;<strong>" + notification.customerName + "</strong></p>";
  html += "<small class='text-muted'><i class='icon-time'></i>&nbsp;" + moment(notification.ts * 1000).fromNow() + "</small>";
  html += "</a>";
  return html;
}

function renderCustomerNotification(notification) {
  var html = '';
  html += "<a id='notification_customer_"+notification.id+"' href='"+notification.link+"'>";
  html += "<p>" + customer_name_msg + "&nbsp;<strong>" + notification.customerName + "</strong></p>";
  html += "<small class='text-muted'><i class='icon-time'></i>&nbsp;" + moment(notification.ts * 1000).fromNow() + "</small>";
  html += "</a>";
  return html;
}

function renderCustomerMessageNotification(notification) {
  var html = '';
  html += "<a id='notification_customer_message_"+notification.id+"' href='"+notification.link+"'>";
  html += "<p>" + from_msg + "&nbsp;<strong>" + notification.from + "</strong></p>";
  html += "<small class='text-muted'><i class='icon-time'></i>&nbsp;" + moment(notification.ts * 1000).fromNow() + "</small>";
  html += "</a>";
  return html;
}