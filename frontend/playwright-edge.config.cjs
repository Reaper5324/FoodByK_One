const base = require('./playwright.config');

module.exports = {
  ...base,
  projects: [{
    name: 'edge',
    use: {
      ...base.use,
      channel: 'msedge',
    },
  }],
  webServer: {
    ...base.webServer,
    reuseExistingServer: true,
  },
};
