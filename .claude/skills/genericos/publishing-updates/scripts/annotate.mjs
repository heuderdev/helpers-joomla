#!/usr/bin/env node
// annotate.mjs — overlay arrows, rectangles and numbered badges on an image.
//
// Uses Sharp + SVG composite. Sharp is resolved via:
//   1. local install in process.cwd() or the skill dir
//   2. global install
//   3. npx --yes sharp-cli fallback (partial support, may skip)
//
// Usage:
//   node annotate.mjs --input /path/in.png --output /path/out.png --annotations-json '[{...}]'
//
// Annotation schema (array):
//   { "type": "arrow" | "rect" | "step" | "resize", "target": "TL|TC|TR|ML|MC|MR|BL|BC|BR",
//     "color": "#ff3b30" (optional, default red),
//     "label": "..." (optional, for arrow callout),
//     "number": 1 (required when type=step),
//     "maxWidth": 1600 (required when type=resize; standalone) }
//
// Exit codes:
//   0 — success
//   1 — argument error
//   2 — image processing error
//   3 — sharp unavailable (caller should fall back to original image)

import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)

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

async function resolveSharp () {
  const candidates = [
    process.cwd(),
    new URL('.', import.meta.url).pathname,
    process.env.HOME
  ]
  for (const base of candidates) {
    try {
      const req = createRequire(`${base}/`)
      return req('sharp')
    } catch {}
  }
  try {
    return require('sharp')
  } catch {
    return null
  }
}

// Map 9-zone target to (x, y) within [0..1] of the image.
function zoneToFraction (zone) {
  const rows = { T: 0.1, M: 0.5, B: 0.9 }
  const cols = { L: 0.1, C: 0.5, R: 0.9 }
  const r = zone[0]
  const c = zone[1]
  return { x: cols[c] ?? 0.5, y: rows[r] ?? 0.5 }
}

// Builds an SVG the same size as the image with all annotations drawn.
function buildSvg (width, height, annotations) {
  const defs = []
  const shapes = []

  // Default arrow marker
  defs.push(`<defs>
    <marker id="arrowhead-red" markerWidth="14" markerHeight="14" refX="10" refY="7" orient="auto" markerUnits="strokeWidth">
      <path d="M0,0 L14,7 L0,14 L4,7 Z" fill="#ff3b30"/>
    </marker>
  </defs>`)

  for (const ann of annotations) {
    const color = ann.color || '#ff3b30'
    const zone = zoneToFraction(ann.target || 'MC')
    const tx = Math.round(zone.x * width)
    const ty = Math.round(zone.y * height)

    if (ann.type === 'arrow') {
      // Arrow starts ~120px away in the direction opposite to the corner so it points INTO the target.
      const dirX = ann.target?.[1] === 'L' ? -1 : ann.target?.[1] === 'R' ? 1 : 0
      const dirY = ann.target?.[0] === 'T' ? -1 : ann.target?.[0] === 'B' ? 1 : 0
      const len = Math.max(100, Math.min(width, height) * 0.15)
      const sx = tx - dirX * len
      const sy = ty - dirY * len
      shapes.push(`<line x1="${sx}" y1="${sy}" x2="${tx}" y2="${ty}"
        stroke="${color}" stroke-width="6" stroke-linecap="round"
        marker-end="url(#arrowhead-red)" />`)
      if (ann.label) {
        const labelX = sx + (dirX * -20)
        const labelY = sy + (dirY * -20)
        shapes.push(`<text x="${labelX}" y="${labelY}"
          font-family="Arial, Helvetica, sans-serif" font-size="28" font-weight="700"
          fill="${color}" paint-order="stroke" stroke="white" stroke-width="4">${escapeXml(ann.label)}</text>`)
      }
    } else if (ann.type === 'rect') {
      const rw = Math.round(width * 0.28)
      const rh = Math.round(height * 0.18)
      const x = Math.max(0, tx - rw / 2)
      const y = Math.max(0, ty - rh / 2)
      shapes.push(`<rect x="${x}" y="${y}" width="${rw}" height="${rh}"
        fill="none" stroke="${color}" stroke-width="6" rx="8" ry="8" />`)
    } else if (ann.type === 'step') {
      const r = 32
      shapes.push(`<circle cx="${tx}" cy="${ty}" r="${r}"
        fill="${color}" stroke="white" stroke-width="4" />`)
      shapes.push(`<text x="${tx}" y="${ty + 10}" text-anchor="middle"
        font-family="Arial, Helvetica, sans-serif" font-size="32" font-weight="800"
        fill="white">${ann.number ?? '?'}</text>`)
    }
  }

  return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
    ${defs.join('\n')}
    ${shapes.join('\n')}
  </svg>`
}

function escapeXml (s) {
  return String(s).replace(/[<>&"']/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c]))
}

async function main () {
  const args = parseArgs(process.argv)

  if (!args.input || !args.output) {
    console.error('Usage: annotate.mjs --input <in> --output <out> [--annotations-json <json>] [--resize <maxWidth>]')
    process.exit(1)
  }

  const sharp = await resolveSharp()
  if (!sharp) {
    console.error('sharp not available; caller should fall back to original image')
    process.exit(3)
  }

  try {
    let pipeline = sharp(args.input)
    const meta = await pipeline.metadata()

    // Optional resize before annotation
    if (args.resize) {
      const maxWidth = parseInt(args.resize, 10)
      if (meta.width > maxWidth) {
        pipeline = pipeline.resize({ width: maxWidth, withoutEnlargement: true })
      }
    }

    let annotations = []
    if (args['annotations-json']) {
      try { annotations = JSON.parse(args['annotations-json']) } catch {
        console.error('annotations-json is not valid JSON')
        process.exit(1)
      }
    }

    if (!annotations.length) {
      await pipeline.toFile(args.output)
      console.log(JSON.stringify({ output: args.output, annotations: 0 }))
      return
    }

    // Need final dimensions to build SVG. Re-pipe through buffer to capture current size.
    const buffer = await pipeline.toBuffer()
    const finalMeta = await sharp(buffer).metadata()
    const svg = buildSvg(finalMeta.width, finalMeta.height, annotations)

    await sharp(buffer)
      .composite([{ input: Buffer.from(svg), top: 0, left: 0 }])
      .toFile(args.output)

    console.log(JSON.stringify({ output: args.output, annotations: annotations.length }))
  } catch (err) {
    console.error('Image processing failed:', err.message)
    process.exit(2)
  }
}

main()
