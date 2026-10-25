#!/usr/bin/env node
/**
 * auth.js — autentica no YouTube via OAuth (Desktop app) e salva o token.
 *
 * Roda uma vez (ou quando o token expirar). Abre o navegador, você autoriza
 * com a conta do canal, e o refresh_token fica salvo em secrets/token.json.
 *
 * Uso:  node scripts/auth.js
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { URL } = require('url');
const { exec } = require('child_process');
const { google } = require('googleapis');

const SKILL_DIR = path.resolve(__dirname, '..');
const CLIENT_SECRET = path.join(SKILL_DIR, 'secrets', 'client_secret.json');
const TOKEN_PATH = path.join(SKILL_DIR, 'secrets', 'token.json');

// Escopo: gerenciar o canal (criar lives, subir thumbnail).
const SCOPES = ['https://www.googleapis.com/auth/youtube.force-ssl'];

function loadClient() {
  const raw = JSON.parse(fs.readFileSync(CLIENT_SECRET, 'utf8'));
  const cfg = raw.installed || raw.web;
  if (!cfg) throw new Error('client_secret.json inválido (sem "installed"/"web")');
  return cfg;
}

function openBrowser(url) {
  const cmd = process.platform === 'darwin' ? 'open'
    : process.platform === 'win32' ? 'start ""' : 'xdg-open';
  exec(`${cmd} "${url}"`);
}

async function main() {
  const cfg = loadClient();
  // Porta fixa do loopback — precisa bater com um redirect_uri autorizado.
  // Desktop apps aceitam http://localhost com qualquer porta.
  const PORT = 4567;
  const redirectUri = `http://localhost:${PORT}`;

  const oAuth2Client = new google.auth.OAuth2(
    cfg.client_id, cfg.client_secret, redirectUri
  );

  const authUrl = oAuth2Client.generateAuthUrl({
    access_type: 'offline',
    prompt: 'consent', // força vir o refresh_token
    scope: SCOPES,
  });

  console.log('\n🔐 Abrindo o navegador pra você autorizar...');
  console.log('Se não abrir, cole esta URL no navegador:\n' + authUrl + '\n');

  const code = await new Promise((resolve, reject) => {
    const server = http.createServer((req, res) => {
      try {
        const u = new URL(req.url, redirectUri);
        const c = u.searchParams.get('code');
        const err = u.searchParams.get('error');
        if (err) {
          res.end('Erro na autorização: ' + err);
          server.close();
          return reject(new Error(err));
        }
        if (c) {
          res.end('✅ Autorizado! Pode fechar esta aba e voltar pro terminal.');
          server.close();
          resolve(c);
        }
      } catch (e) { reject(e); }
    });
    server.listen(PORT, () => openBrowser(authUrl));
    server.on('error', reject);
  });

  const { tokens } = await oAuth2Client.getToken(code);
  fs.writeFileSync(TOKEN_PATH, JSON.stringify(tokens, null, 2));
  fs.chmodSync(TOKEN_PATH, 0o600);
  console.log('\n✅ Token salvo em secrets/token.json. Autenticação concluída.');
}

main().catch((e) => {
  console.error('\n❌ Falha na autenticação:', e.message);
  process.exit(1);
});
