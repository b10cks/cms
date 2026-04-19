import assert from 'node:assert/strict'

process.env.VITE_APP_ILUM_BASE_URL = '/ilum'

const { default: useHandlebars } = await import('./useHandlebars.ts')

const handlebars = useHandlebars()

const listResult = handlebars.render(
  '{{#each items}}{{@index}}:{{this.name}} {{/each}}',
  {
    items: [
      { name: 'Alpha' },
      { name: 'Beta' },
    ],
  }
)

assert.equal(listResult, '0:Alpha 1:Beta ')

const ifResult = handlebars.render(
  '{{#if image}}{{image image}}{{else}}fallback{{/if}}',
  {
    image: {
      full_path: '/storage/demo/asset/cover.jpg',
    },
  }
)

assert.equal(ifResult, '/ilum/storage/demo/asset/cover.jpg/w_64,h_64')

const nestedResult = handlebars.render(
  '{{title}}: {{#each items}}{{../title}}/{{@key}}={{this}}{{/each}}',
  {
    title: 'Gallery',
    items: {
      first: 'One',
      second: 'Two',
    },
  }
)

assert.equal(nestedResult, 'Gallery: Gallery/first=OneGallery/second=Two')

const nestedElseResult = handlebars.render(
  '{{#if items}}{{#each items}}{{#if this.visible}}{{this.name}}{{else}}hidden{{/if}}{{/each}}{{else}}empty{{/if}}',
  {
    items: [
      { name: 'Visible', visible: true },
      { name: 'Hidden', visible: false },
    ],
  }
)

assert.equal(nestedElseResult, 'Visiblehidden')

const elseResult = handlebars.render(
  '{{#if missing}}visible{{else}}hidden{{/if}}',
  {}
)

assert.equal(elseResult, 'hidden')

console.log('useHandlebars tests passed')
