import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

// Colour utilities (text-gray-600, bg-indigo-600 and so on) still appear in the Jetstream views. They resolve to the Shelf Edge palette:
// the triplets live in resources/css/tokens.css ("Tailwind bridge"). New views use the ds components and the tokens, not colour utilities.
const scale = (name, steps) => Object.fromEntries(steps.map((s) => [s, `rgb(var(--tw-${name}-${s}) / <alpha-value>)`]));

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './resources/views/**/*.blade.php',
    ],

    // The theme switch puts class="dark" on <html> once a person has chosen dark; with no choice dark: variants follow nothing, and the
    // tokens (light-dark()) carry the system preference. Views must not rely on dark: variants.
    darkMode: 'class',

    theme: {
        extend: {
            colors: {
                gray: scale('gray', [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950]),
                indigo: scale('indigo', [50, 100, 200, 300, 400, 500, 600, 700, 800, 900]),
                red: scale('red', [100, 400, 500, 600, 700]),
                green: scale('green', [400, 500, 600]),
                yellow: scale('yellow', [500, 600]),
            },
            ringColor: { DEFAULT: 'rgb(var(--tw-indigo-600) / 0.5)' },
            ringOffsetColor: { DEFAULT: 'var(--surface)' },
            fontFamily: {
                sans: ['var(--font-text)', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms({ strategy: 'class' }), typography],
};
