module.exports = {
  apps: [
    {
      name: 'catalog-api',
      script: './server.js',
      env: {
        NODE_ENV: 'production'
      }
    }
  ]
};