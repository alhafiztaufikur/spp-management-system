// Compatible entry point for the explicit source-history, free-order regression.
if (!process.env.SPP_PROMOTION_ARTIFACTS && process.env.SPP_UI_ARTIFACTS) {
  process.env.SPP_PROMOTION_ARTIFACTS = require('node:path').dirname(process.env.SPP_UI_ARTIFACTS);
}
require('./flexible_promotion_browser_test.js');
