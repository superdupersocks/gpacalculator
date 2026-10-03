var gulp = require('gulp');
var uglify = require('gulp-uglify');
var concat = require('gulp-concat');
var ngAnnotate = require('gulp-ng-annotate');

var scripts_path = [
  'js/src/angular.js',
  'js/src/*.js'
];

gulp.task('scripts', function() {
  gulp.src(scripts_path)
    .pipe(concat('application.js'))
    .pipe(ngAnnotate())
    .pipe(uglify())
    .pipe(gulp.dest('./js/'));
});

gulp.task('default', function() {
  gulp.watch(scripts_path, ['scripts']);
});