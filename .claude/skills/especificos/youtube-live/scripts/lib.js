/**
 * lib.js — helpers compartilhados: cliente OAuth autenticado + YouTube API.
 */
const fs = require('fs');
const path = require('path');
const { google } = require('googleapis');

const SKILL_DIR = path.resolve(__dirname, '..');
const CLIENT_SECRET = path.join(SKILL_DIR, 'secrets', 'client_secret.json');
const TOKEN_PATH = path.join(SKILL_DIR, 'secrets', 'token.json');

function getAuthedClient() {
  if (!fs.existsSync(TOKEN_PATH)) {
    throw new Error('SEM_TOKEN'); // sinaliza que precisa rodar auth.js
  }
  const raw = JSON.parse(fs.readFileSync(CLIENT_SECRET, 'utf8'));
  const cfg = raw.installed || raw.web;
  const oAuth2Client = new google.auth.OAuth2(
    cfg.client_id, cfg.client_secret, `http://localhost:4567`
  );
  const tokens = JSON.parse(fs.readFileSync(TOKEN_PATH, 'utf8'));
  oAuth2Client.setCredentials(tokens);
  // persiste refresh automático do access_token
  oAuth2Client.on('tokens', (t) => {
    const merged = { ...tokens, ...t };
    fs.writeFileSync(TOKEN_PATH, JSON.stringify(merged, null, 2));
  });
  return oAuth2Client;
}

function getYoutube() {
  return google.youtube({ version: 'v3', auth: getAuthedClient() });
}

module.exports = { getYoutube, SKILL_DIR };
