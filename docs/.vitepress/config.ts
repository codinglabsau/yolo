import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'YOLO',
  description: 'Deploy Laravel apps to AWS Fargate',
  base: '/yolo/',
  cleanUrls: true,

  head: [
    // A 🚀 emoji rendered as an inline SVG — no binary asset to ship, and a
    // data URI sidesteps VitePress not prepending `base` to head link hrefs.
    ['link', { rel: 'icon', href: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🚀</text></svg>' }],
  ],

  themeConfig: {
    logo: '/logo.png',
    siteTitle: false,

    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Reference', link: '/reference/commands' },
    ],

    // One consolidated sidebar shown on every page, so the Guide and the
    // Reference are always reachable from each other (VitePress otherwise scopes
    // a path-keyed sidebar to its own section).
    sidebar: [
      {
        text: 'Introduction',
        items: [
          { text: 'What is YOLO?', link: '/guide/what-is-yolo' },
          { text: 'Getting Started', link: '/guide/getting-started' },
        ],
      },
      {
        text: 'Essentials',
        items: [
          { text: 'The Container Image', link: '/guide/images' },
          { text: 'Environment Files', link: '/guide/environment-files' },
          { text: 'Provisioning', link: '/guide/provisioning' },
          { text: 'Building & Deploying', link: '/guide/building-and-deploying' },
        ],
      },
      {
        text: 'Features',
        items: [
          { text: 'Domains', link: '/guide/domains' },
          { text: 'Multi-Tenancy', link: '/guide/multi-tenancy' },
          { text: 'Scaling', link: '/guide/scaling' },
          { text: 'Status Dashboard', link: '/guide/status-dashboard' },
          { text: 'The /yolo Skill', link: '/guide/the-yolo-skill' },
          { text: 'CI/CD', link: '/guide/ci-cd' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'Commands', link: '/reference/commands' },
          { text: 'Manifest', link: '/reference/manifest' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/codinglabsau/yolo' },
    ],

    search: {
      provider: 'local',
    },

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright &copy; Coding Labs',
    },
  },
})
