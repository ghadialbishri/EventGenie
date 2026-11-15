// tailwind.config.js
module.exports = {
  content: ["./*.html"],
  theme: {
    extend: {
      colors: {
        brand: {
          light: "#6ee7b7", // emerald light
          DEFAULT: "#059669", // emerald-600
          dark: "#047857", // emerald-700
        },
        accent: {
          light: "#67e8f9", // cyan-300
          DEFAULT: "#06b6d4", // cyan-500
          dark: "#0891b2", // cyan-600
        },
      },
      fontFamily: {
        sans: ["Inter", "system-ui", "sans-serif"],
      },
    },
  },
  plugins: [],
};
