const fs = require('fs')
const { parse, compileScript, compileTemplate } = require('@vue/compiler-sfc')
let bad = 0
for (const file of process.argv.slice(2)) {
  const source = fs.readFileSync(file, 'utf8')
  try {
    const { descriptor, errors } = parse(source, { filename: file })
    if (errors.length) throw new Error(errors.map(e => e.message).join('; '))
    const id = 'x'
    if (descriptor.script || descriptor.scriptSetup) compileScript(descriptor, { id })
    if (descriptor.template) {
      const r = compileTemplate({ source: descriptor.template.content, filename: file, id })
      if (r.errors.length) throw new Error(r.errors.map(e => String(e)).join('; '))
    }
    console.log('ok   ' + file)
  } catch (e) { bad++; console.log('FAIL ' + file + '\n     ' + e.message) }
}
process.exit(bad ? 1 : 0)
