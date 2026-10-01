(function() {
  var $ = jQuery;
  var cdb = angular.module('cdb', ['cdb.assets', 'cdb.preloaded', 'ngAnimate']);

  cdb.config(function ($httpProvider) {
    $httpProvider.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
    $httpProvider.defaults.transformRequest = function(data){
      if (data === undefined) {
        return data;
      }
      return $.param(data);
    }
  });

  cdb.directive('slider', function(){
    return function(scope, element, attrs){
        scope.$watch('score_type', function(){
          element.slider(scope.score_type.slider).slider('option', {
            slide: function(e, ui){
              scope.score = ui.value;
              scope.$apply();
            }
          });
          scope.score = scope.score_type.slider.value;
          scope.slider_min = scope.score_type.slider.min;
          scope.slider_max = Math.round(scope.score_type.slider.max);
        });
      }
  });

  cdb.factory('table', function($http, ajaxURL){
    return {
      getEntries: function(data, callback){
        $http.post(ajaxURL, data).success(callback);
      }
    }
  });

  var scrollToTable = function() {
    $('html, body').animate({
      scrollTop: $('#college_db_full').position().top
    });
  };

  cdb.controller('MainCtrl', function($scope, table, assets, $preloaded) {
    $scope.loading = false;
    $scope.states = assets.states;
    $scope.score_types = assets.score_types;

    $scope.state = $preloaded.defaults.state;
    $scope.score_type = $scope.score_types.filter(function(el){
      return el.name == $preloaded.defaults.score_type;
    })[0];
    $scope.score = $preloaded.defaults.score;

    $scope.tableEntries = $preloaded.entries.rows;
    $scope.pagination = $preloaded.entries.pagination;
    $scope.page = 1;

    $scope.search = function(page){
      $scope.loading = true;
      if(page !== undefined){
        $scope.page = page;
      }
      var data = {
        action: 'cdb_search',
        state: $scope.state,
        score_type: $scope.score_type.name,
        score: $scope.score,
        page: $scope.page
      };
      table.getEntries(data, function(entries){
        $scope.tableEntries = entries.rows;
        $scope.pagination = entries.pagination;
        $scope.loading = false;
        scrollToTable();
      });
    };
  });

}());