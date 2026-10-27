module.exports = {
    content: ['./*.php', './{admin,ai,api,includes,play,q,student}/**/*.php', './engine/**/*.js'],
    // Legacy mode buttons construct these colours at runtime.
    safelist: ['blue','purple','orange','green'].flatMap(color => [
        `bg-${color}-500`, `border-${color}-600`, `hover:border-${color}-400`
    ]),
    theme: {extend: {}},
    plugins: []
};
