#!/usr/bin/env node
// git-digest.mjs — walk configured repos and emit a structured JSON digest of recent changes.
//
// Usage:
//   node git-digest.mjs --config /path/to/project.json --since "6 hours ago"
//   node git-digest.mjs --config /path/to/project.json --since-commit origin/main
//
// Output (stdout):
//   {
//     "window": { "since": "6 hours ago", "until": "now" },
//     "repos": [
//       {
//         "path": "/abs/path/to/repo",
//         "branch": "main",
//         "commits": [
//           { "sha": "abcd1234", "author": "...", "date": "...", "type": "feat", "scope": "editor", "breaking": false, "subject": "...", "body": "...", "files": ["a.js", "b.js"] }
//         ],
//         "filesChanged": 14,
//         "insertions": 420,
//         "deletions": 80
//       }
//     ],
//     "totals": { "commits": 5, "repos": 2 }
//   }

import { readFileSync, existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import path from 'node:path'

// Tiny glob matcher (no deps) — supports **/, *, ?, and exact literals.
// Good enough for ignoreGlobs like "**/CLAUDE.md", "**/.claude/**", "**/node_modules/**".
function globToRegExp (pattern) {
  let re = ''
  let i = 0
  while (i < pattern.length) {
    const c = pattern[i]
    if (c === '*') {
      if (pattern[i + 1] === '*') {
        // ** => any number of path segments
        re += '.*'
        i += 2
        if (pattern[i] === '/') i++
        continue
      }
      re += '[^/]*'
    } else if (c === '?') {
      re += '[^/]'
    } else if ('.+^$(){}|\\'.includes(c)) {
      re += '\\' + c
    } else {
      re += c
    }
    i++
  }
  return new RegExp('^' + re + '$')
}

function globMatch (filePath, pattern) {
  return globToRegExp(pattern).test(filePath)
}

function parseArgs (argv) {
  const args = {}
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i]
    if (a.startsWith('--')) {
      const key = a.slice(2)
      const next = argv[i + 1]
      if (!next || next.startsWith('--')) {
        args[key] = true
      } else {
        args[key] = next
        i++
      }
    }
  }
  return args
}

function git (cwd, args) {
  try {
    return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim()
  } catch (err) {
    return ''
  }
}

function parseCommitLine (raw) {
  // Format: %H|%an|%ad|%s
  const [sha, author, date, ...rest] = raw.split('|')
  const subject = rest.join('|')
  // Conventional Commits: <type>(scope)!: subject   OR   <type>: subject
  const m = subject.match(/^(\w+)(?:\(([^)]+)\))?(!)?:\s*(.+)$/)
  let type = null
  let scope = null
  let breaking = false
  let title = subject
  if (m) {
    type = m[1]
    scope = m[2] || null
    breaking = m[3] === '!'
    title = m[4]
  }
  return { sha, author, date, type, scope, breaking, subject: title, rawSubject: subject }
}

function looksLikeNoise (subject) {
  const noisePatterns = [/^wip\b/i, /^temp\b/i, /^asdf\b/i, /^test$/i, /\[skip changelog\]/i, /\[internal\]/i]
  return noisePatterns.some(p => p.test(subject))
}

function matchesIgnore (filePath, ignoreGlobs) {
  if (!ignoreGlobs || !ignoreGlobs.length) return false
  return ignoreGlobs.some(pattern => globMatch(filePath, pattern))
}

function digestRepo (repoPath, config, since, sinceCommit) {
  if (!existsSync(path.join(repoPath, '.git'))) {
    return { path: repoPath, error: 'Not a git repo', commits: [] }
  }

  const branch = git(repoPath, ['rev-parse', '--abbrev-ref', 'HEAD'])

  let logRange
  if (sinceCommit) {
    logRange = `${sinceCommit}..HEAD`
  } else {
    logRange = null
  }

  const logArgs = [
    'log',
    '--no-merges',
    '--pretty=format:%H|%an|%ad|%s',
    '--date=iso'
  ]
  if (logRange) logArgs.push(logRange)
  if (since && !sinceCommit) logArgs.push(`--since=${since}`)

  const rawLog = git(repoPath, logArgs)
  if (!rawLog) {
    return { path: repoPath, branch, commits: [], filesChanged: 0, insertions: 0, deletions: 0 }
  }

  const lines = rawLog.split('\n').filter(Boolean)
  const keepTypes = new Set(config.keepCommitTypes || ['feat', 'fix', 'perf', 'improve'])
  const ignoreGlobs = config.ignoreGlobs || []

  const commits = []
  for (const line of lines) {
    const parsed = parseCommitLine(line)
    if (looksLikeNoise(parsed.subject)) continue

    // If type is known and not in keep list, skip (unless breaking)
    if (parsed.type && !keepTypes.has(parsed.type) && !parsed.breaking) continue

    // Body
    const body = git(repoPath, ['log', '-1', '--pretty=format:%b', parsed.sha])

    // Files touched
    const filesRaw = git(repoPath, ['show', '--name-only', '--pretty=format:', parsed.sha])
    const files = filesRaw.split('\n').map(s => s.trim()).filter(Boolean)

    // Filter by ignoreGlobs — if ALL files match ignore, drop the commit
    const userFacingFiles = files.filter(f => !matchesIgnore(f, ignoreGlobs))
    if (files.length && userFacingFiles.length === 0) continue

    commits.push({
      sha: parsed.sha.slice(0, 8),
      author: parsed.author,
      date: parsed.date,
      type: parsed.type,
      scope: parsed.scope,
      breaking: parsed.breaking,
      subject: parsed.subject,
      body,
      files: userFacingFiles
    })
  }

  // Stats
  const shortstatArgs = ['log', '--shortstat', '--pretty=format:', '--no-merges']
  if (logRange) shortstatArgs.push(logRange)
  if (since && !sinceCommit) shortstatArgs.push(`--since=${since}`)
  const statRaw = git(repoPath, shortstatArgs)
  let filesChanged = 0
  let insertions = 0
  let deletions = 0
  for (const line of statRaw.split('\n')) {
    const mFiles = line.match(/(\d+) files? changed/)
    const mIns = line.match(/(\d+) insertions?\(\+\)/)
    const mDel = line.match(/(\d+) deletions?\(-\)/)
    if (mFiles) filesChanged += Number(mFiles[1])
    if (mIns) insertions += Number(mIns[1])
    if (mDel) deletions += Number(mDel[1])
  }

  return { path: repoPath, branch, commits, filesChanged, insertions, deletions }
}

async function main () {
  const args = parseArgs(process.argv)

  if (!args.config) {
    console.error(JSON.stringify({ error: 'Missing --config /path/to/project.json' }))
    process.exit(1)
  }

  let config
  try {
    config = JSON.parse(readFileSync(args.config, 'utf8'))
  } catch (err) {
    console.error(JSON.stringify({ error: `Cannot read config: ${err.message}` }))
    process.exit(1)
  }

  const since = args.since || `${config.defaultWindowHours || 12} hours ago`
  const sinceCommit = args['since-commit'] || null

  const results = []
  for (const repoPath of config.repos || []) {
    const abs = repoPath.startsWith('/') ? repoPath : path.resolve(process.env.HOME, repoPath)
    results.push(digestRepo(abs, config, since, sinceCommit))
  }

  const totals = {
    repos: results.filter(r => !r.error).length,
    commits: results.reduce((acc, r) => acc + (r.commits?.length || 0), 0)
  }

  process.stdout.write(JSON.stringify({
    window: { since, sinceCommit, until: 'now' },
    repos: results,
    totals
  }, null, 2))
}

main().catch(err => {
  console.error(JSON.stringify({ error: err.message, stack: err.stack }))
  process.exit(1)
})
