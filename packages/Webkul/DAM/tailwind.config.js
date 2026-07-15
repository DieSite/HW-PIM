/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ["./src/Resources/**/*.blade.php", "./src/Resources/**/*.js"],

    theme: {
        container: {
            center: true,

            screens: {
                "2xl": "1920px",
            },

            padding: {
                DEFAULT: "16px",
            },
        },

        screens: {
            sm: "525px",
            md: "768px",
            lg: "1024px",
            xl: "1240px",
            "2xl": "1920px",
        },

        extend: {
            colors: {
                // Brand primary — remap Tailwind's "violet" scale to HW orange
                // (#ff6700 at 600) so every violet-* utility matches the admin.
                violet: {
                    50: '#fff3ea',
                    100: '#ffe3d1',
                    200: '#ffc4a3',
                    300: '#ffa16b',
                    400: '#ff8438',
                    500: '#ff7314',
                    600: '#ff6700',
                    700: '#cc5200',
                    800: '#a03f00',
                    900: '#7d3200',
                    950: '#431900',
                },
                cherry: {
                    600: '#353061',
                    700: '#28273F',
                    800: '#1F1C30',
                    900: '#26283D',
                },
                sky: {
                    500: '#0C8CE9',
                }
            },

            fontFamily: {
                inter: ['Inter'],
                icon: ['icomoon']
            }
        },
    },
    
    darkMode: 'class',

    plugins: [],

    safelist: [
        {
            pattern: /icon-dam-/,
        }
    ]
};
