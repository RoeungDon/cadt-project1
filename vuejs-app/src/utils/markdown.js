// A small Markdown renderer for the help pages (headings, lists, tables, images, bold, italic, code).
// The help text is written by us and shipped with the app; HTML in it is escaped anyway.

const escape = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

function inline(text) {
  return escape(text)
    .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, alt, src) => `<img src="${src}" alt="${alt}" loading="lazy">`)
    .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, label, href) =>
      /^(https?:|\/|#)/.test(href) ? `<a href="${href}">${label}</a>` : label)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')
}

const cells = (row) => row.replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map((c) => inline(c.trim().replace(/\\\|/g, '|')))

export function renderMarkdown(source) {
  const lines = source.replace(/\r/g, '').split('\n')
  const html = []
  let i = 0

  while (i < lines.length) {
    const line = lines[i]

    if (!line.trim()) { i++; continue }

    if (line.startsWith('```')) {
      const code = []
      for (i++; i < lines.length && !lines[i].startsWith('```'); i++) code.push(escape(lines[i]))
      html.push(`<pre><code>${code.join('\n')}</code></pre>`)
      i++
      continue
    }

    const heading = line.match(/^(#{1,4})\s+(.*)$/)
    if (heading) {
      const level = heading[1].length
      const id = heading[2].match(/^([កខគឃ]\.\d+)/)?.[1] ?? ''
      html.push(`<h${level}${id ? ` id="${id}"` : ''}>${inline(heading[2])}</h${level}>`)
      i++
      continue
    }

    if (line.startsWith('|') && lines[i + 1]?.match(/^\|\s*-/)) {
      const head = cells(line)
      const body = []
      for (i += 2; i < lines.length && lines[i].startsWith('|'); i++) body.push(cells(lines[i]))
      html.push(`<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr>${head.map((c) => `<th>${c}</th>`).join('')}</tr></thead><tbody>${body.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`)
      continue
    }

    const list = line.match(/^(\s*)(\d+\.|-)\s+/)
    if (list) {
      const ordered = list[2] !== '-'
      const items = []
      while (i < lines.length && lines[i].match(/^\s*(\d+\.|-)\s+/)) {
        const sub = lines[i].match(/^(\s+)(\d+\.|-)\s+(.*)$/)
        if (sub && items.length) items[items.length - 1] += `<br><span class="sub">• ${inline(sub[3])}</span>`
        else items.push(inline(lines[i].replace(/^\s*(\d+\.|-)\s+(\[ \]\s+)?/, '')))
        i++
      }
      const tag = ordered ? 'ol' : 'ul'
      html.push(`<${tag}>${items.map((it) => `<li>${it}</li>`).join('')}</${tag}>`)
      continue
    }

    const para = []
    while (i < lines.length && lines[i].trim() && !lines[i].match(/^(#{1,4}\s|\||```|\s*(\d+\.|-)\s)/)) para.push(lines[i++])
    const text = para.join(' ')
    html.push(text.match(/^!\[/) ? `<figure>${inline(text)}</figure>` : `<p>${inline(text)}</p>`)
  }

  return html.join('\n')
}
