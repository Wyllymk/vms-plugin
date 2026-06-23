/**
 * Grunt build configuration for VMS Plugin.
 *
 * Tasks:
 *   grunt i18n     — regenerate .pot file
 *   grunt readme   — convert readme.txt → README.md
 *   grunt build    — clean, copy, generate pot, compress to zip
 *   grunt watch    — rebuild pot on PHP file changes
 */

module.exports = function (grunt) {
	'use strict';

	const pkg = grunt.file.readJSON('package.json');

	grunt.initConfig({
		pkg,

		// ---------------------------------------------------------------
		// Clean build artifacts
		// ---------------------------------------------------------------
		clean: {
			build: ['build/'],
		},

		// ---------------------------------------------------------------
		// Copy distributable files (exclude dev-only files)
		// ---------------------------------------------------------------
		copy: {
			build: {
				expand: true,
				src: [
					'**',
					'!build/**',
					'!node_modules/**',
					'!tests/**',
					'!bin/**',
					'!coverage/**',
					'!.git/**',
					'!.github/**',
					'!**/*.map',
					'!Gruntfile.js',
					'!package*.json',
					'!composer.lock',
					'!.phpcs.xml.dist',
					'!.gitignore',
					'!.editorconfig',
					'!.eslintrc*',
					'!phpunit.xml*',
					// Strip composer dev deps from vendor
					'!vendor/bin/**',
					'!vendor/**/tests/**',
					'!vendor/**/Tests/**',
					'!vendor/**/test/**',
					'!vendor/**/.git/**',
					'!vendor/**/*.md',
					'!vendor/**/composer.json',
					'!vendor/**/phpunit.xml*',
					'!vendor/squizlabs/**',
					'!vendor/wp-coding-standards/**',
					'!vendor/phpcompatibility/**',
					'!vendor/dealerdirect/**',
					'!vendor/phpunit/**',
					'!vendor/yoast/**',
					'!vendor/brain/**',
					'!vendor/php-stubs/**',
					'!vendor/sebastian/**',
					'!vendor/phar-io/**',
					'!vendor/nikic/**',
					'!vendor/myclabs/**',
					'!vendor/theseer/**',
					'!vendor/doctrine/**',
					'!vendor/antecedent/**',
				],
				dest: 'build/<%= pkg.name %>/',
			},
		},

		// ---------------------------------------------------------------
		// Generate .pot translation template
		// ---------------------------------------------------------------
		makepot: {
			target: {
				options: {
					domainPath: '/languages',
					mainFile: 'vms-plugin.php',
					potFilename: 'vms-plugin.pot',
					type: 'wp-plugin',
					exclude: ['build/.*', 'node_modules/.*', 'vendor/.*', 'tests/.*'],
					potHeaders: {
						'report-msgid-bugs-to': 'https://github.com/Wyllymk/vms-plugin/issues',
						'language-team': 'LANGUAGE <LL@li.org>',
						poedit: true,
						'x-poedit-keywordslist': true,
					},
					updateTimestamp: false,
				},
			},
		},

		// ---------------------------------------------------------------
		// readme.txt → README.md
		// ---------------------------------------------------------------
		wp_readme_to_markdown: {
			target: {
				files: {
					'README.md': 'readme.txt',
				},
			},
		},

		// ---------------------------------------------------------------
		// Zip the build directory
		// ---------------------------------------------------------------
		compress: {
			build: {
				options: {
					archive: 'build/<%= pkg.name %>-<%= pkg.version %>.zip',
					mode: 'zip',
				},
				files: [
					{
						expand: true,
						cwd: 'build/<%= pkg.name %>/',
						src: ['**/*'],
						dest: '<%= pkg.name %>/',
					},
				],
			},
		},

		// ---------------------------------------------------------------
		// Shell commands
		// ---------------------------------------------------------------
		shell: {
			composerNoDev: {
				command: 'composer install --no-dev --optimize-autoloader --quiet',
			},
			composerDev: {
				command: 'composer install --quiet',
			},
		},

		// ---------------------------------------------------------------
		// Watch for changes
		// ---------------------------------------------------------------
		watch: {
			php: {
				files: ['**/*.php', '!build/**', '!vendor/**', '!node_modules/**'],
				tasks: ['makepot'],
			},
		},
	});

	// Load tasks.
	grunt.loadNpmTasks('grunt-contrib-clean');
	grunt.loadNpmTasks('grunt-contrib-copy');
	grunt.loadNpmTasks('grunt-contrib-compress');
	grunt.loadNpmTasks('grunt-contrib-watch');
	grunt.loadNpmTasks('grunt-wp-i18n');
	grunt.loadNpmTasks('grunt-wp-readme-to-markdown');
	grunt.loadNpmTasks('grunt-shell');

	// Composite tasks.
	grunt.registerTask('i18n', ['makepot']);
	grunt.registerTask('readme', ['wp_readme_to_markdown']);

	grunt.registerTask('build', [
		'clean:build',
		'shell:composerNoDev',
		'makepot',
		'copy:build',
		'compress:build',
		'shell:composerDev', // Restore dev deps for continued development.
	]);

	grunt.registerTask('default', ['i18n', 'readme']);
};
