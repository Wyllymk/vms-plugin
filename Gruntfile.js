module.exports = function (grunt) {
	"use strict";

	// Project configuration
	grunt.initConfig({
		pkg: grunt.file.readJSON("package.json"),

		// -----------------------------
		// Add textdomain to plugin PHP files
		// -----------------------------
		addtextdomain: {
			options: {
				textdomain: "vms-plugin",
			},
			update_all_domains: {
				options: {
					updateDomains: true,
				},
				src: [
					"*.php",
					"**/*.php",
					"!.git/**/*",
					"!bin/**/*",
					"!node_modules/**/*",
					"!vendor/**/*",
					"!tests/**/*",
					"!build/**/*",
				],
			},
		},

		// -----------------------------
		// Generate README.md from readme.txt
		// -----------------------------
		wp_readme_to_markdown: {
			your_target: {
				files: {
					"README.md": "readme.txt",
				},
			},
		},

		// -----------------------------
		// Generate POT file for translations
		// -----------------------------
		makepot: {
			target: {
				options: {
					domainPath: "/languages",
					exclude: [
						".git/*",
						"bin/*",
						"node_modules/*",
						"vendor/*",
						"tests/*",
						"build/*",
					],
					mainFile: "vms-plugin.php",
					potFilename: "vms-plugin.pot",
					potHeaders: {
						poedit: true,
						"x-poedit-keywordslist": true,
					},
					type: "wp-plugin",
					updateTimestamp: true,
				},
			},
		},

		// -----------------------------
		// Clean build directory before packaging
		// -----------------------------
		clean: {
			build: ["build/"],
		},

		// -----------------------------
		// Copy plugin files to build directory
		// -----------------------------
		copy: {
			build: {
				expand: true,
				src: [
					"**",
					"!node_modules/**",
					"!vendor/**",
					"!.git/**",
					"!bin/**",
					"!build/**",
					"!tests/**",
					"!Gruntfile.js",
					"!package.json",
					"!package-lock.json",
					"!composer.json",
					"!composer.lock",
				],
				dest: "build/vms-plugin/",
			},
		},

		// -----------------------------
		// Compress build directory into a ZIP
		// -----------------------------
		compress: {
			build: {
				options: {
					archive: "build/vms-plugin.zip",
				},
				expand: true,
				cwd: "build/",
				src: ["vms-plugin/**"],
			},
		},
	});

	// Load required Grunt plugins
	grunt.loadNpmTasks("grunt-wp-i18n");
	grunt.loadNpmTasks("grunt-wp-readme-to-markdown");
	grunt.loadNpmTasks("grunt-contrib-clean");
	grunt.loadNpmTasks("grunt-contrib-copy");
	grunt.loadNpmTasks("grunt-contrib-compress");

	// -----------------------------
	// Register Tasks
	// -----------------------------
	grunt.registerTask("default", ["i18n", "readme"]);
	grunt.registerTask("i18n", ["addtextdomain", "makepot"]);
	grunt.registerTask("readme", ["wp_readme_to_markdown"]);
	grunt.registerTask("build", ["clean", "copy", "compress"]);

	grunt.util.linefeed = "\n";
};
