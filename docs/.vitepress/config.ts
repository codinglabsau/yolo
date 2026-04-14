import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'YOLO',
  description: 'Deploy high-availability Laravel apps to AWS',
  base: '/yolo/',

  head: [
    ['link', { rel: 'icon', href: '/logo.png' }],
  ],

  themeConfig: {
    logo: '/logo.png',
    siteTitle: false,

    nav: [
      { text: 'Guide', link: '/guide/getting-started' },
      { text: 'Reference', link: '/reference/commands' },
    ],

    sidebar: {
      '/guide/': [
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
            { text: 'Provisioning', link: '/guide/provisioning' },
            { text: 'Images', link: '/guide/images' },
            { text: 'Environment Files', link: '/guide/environment-files' },
            { text: 'Building & Deploying', link: '/guide/building-and-deploying' },
          ],
        },
        {
          text: 'Features',
          items: [
            { text: 'Domains', link: '/guide/domains' },
            { text: 'Multi-Tenancy', link: '/guide/multi-tenancy' },
            { text: 'CI/CD', link: '/guide/ci-cd' },
          ],
        },
      ],
      '/reference/': [
        {
          text: 'Reference',
          items: [
            { text: 'Commands', link: '/reference/commands' },
            { text: 'Manifest', link: '/reference/manifest' },
          ],
        },
      ],
    },

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
