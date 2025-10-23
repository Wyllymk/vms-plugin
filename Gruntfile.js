module.exports = function (grunt) {
	"use strict";

	// Project configuration
	grunt.initConfig({
		pkg: grunt.file.readJSON("package.json"),

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
					"!node_modules/**",
					"!vendor/**",
					"!tests/**",
				],
			},
		},

		makepot: {
			target: {
				options: {
					domainPath: "/languages",
					exclude: ["node_modules/*", "vendor/*", "tests/*"],
					mainFile: "vms-plugin.php",
					potFilename: "vms-plugin.pot",
					type: "wp-plugin",
					updateTimestamp: true,
				},
			},
		},

		wp_readme_to_markdown: {
			your_target: {
				files: {
					"README.md": "readme.txt",
				},
			},
		},

		clean: {
			build: ["build/"],
		},

		copy: {
			build: {
				files: [
					{
						expand: true,
						src: [
							"**",
							"!node_modules/**",
							"!build/**",
							"!Gruntfile.js",
							"!package*.json",
							"!composer.*",
							"!tests/**",
						],
						dest: "build/vms-plugin/",
					},
				],
			},
		},

		compress: {
			build: {
				options: {
					archive: "build/vms-plugin.zip",
				},
				files: [
					{
						expand: true,
						cwd: "build/vms-plugin/",
						src: ["**"],
						dest: "vms-plugin/",
					},
				],
			},
		},

		shell: {
			composer: {
				command: "composer install --no-dev",
			},
		},
	});

	// Load tasks
	grunt.loadNpmTasks("grunt-wp-i18n");
	grunt.loadNpmTasks("grunt-wp-readme-to-markdown");
	grunt.loadNpmTasks("grunt-contrib-clean");
	grunt.loadNpmTasks("grunt-contrib-copy");
	grunt.loadNpmTasks("grunt-contrib-compress");
	grunt.loadNpmTasks("grunt-shell");

	// Register tasks
	grunt.registerTask("i18n", ["addtextdomain", "makepot"]);
	grunt.registerTask("readme", ["wp_readme_to_markdown"]);
	grunt.registerTask("build", [
		"clean",
		"shell:composer",
		"copy",
		"compress",
	]);
	grunt.registerTask("default", ["i18n", "readme"]);

	grunt.util.linefeed = "\n";
};
