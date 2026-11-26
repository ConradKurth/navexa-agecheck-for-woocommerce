// The checkout block side of the wp-env test gateway (navexa-agecheck-test-gateway.php).
;(function () {
  var registry = window.wc && window.wc.wcBlocksRegistry
  var el = window.wp && window.wp.element && window.wp.element.createElement
  if (!registry || !el) return
  var label = "Test card (declines)"
  registry.registerPaymentMethod({
    name: "navexa_test_decline",
    label: el("span", null, label),
    ariaLabel: label,
    content: el("p", null, "Every payment is declined."),
    edit: el("p", null, "Every payment is declined."),
    canMakePayment: function () {
      return true
    },
    supports: { features: ["products"] },
  })
})()
