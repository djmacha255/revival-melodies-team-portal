/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './public/**/*.php',
    './public/**/*.js',
    './app/**/*.php',
    './create_admin.php',
  ],
  theme: {
    extend: {
      colors: {
        ink: '#111827',
        brand: {
          50: '#eef2ff',
          100: '#e0e7ff',
          600: '#4f46e5',
          700: '#4338ca',
          800: '#3730a3',
          900: '#312e81',
        },
      },
      boxShadow: {
        soft: '0 12px 36px rgba(31, 41, 55, .08)',
      },
    },
  },
  plugins: [],
};
