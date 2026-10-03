(function() {
  var cdb = angular.module('cdb.assets', []);

  cdb.factory('assets', function(){
    return {
      score_types: [
        {
          name: 'GPA',
          slider: {
            min: 2,
            max: 5.1,
            step: 0.1,
            value: 5,
            range: "min"
          }
        },
        {
          name: 'SAT',
          slider: {
            min: 0,
            max: 2400,
            step: 5,
            value: 0,
            range: "min"
          }
        },
        {
          name: 'ACT',
          slider: {
            min: 0,
            max: 36,
            step: 1,
            value: 0,
            range: "min"
          }
        }
      ],
      states: [
        'All',
        'Alabama',
        'Alaska',
        'American Samoa',
        'Arizona',
        'Arkansas',
        'California',
        'Colorado',
        'Connecticut',
        'Delaware',
        'District of Columbia',
        'Florida',
        'Georgia',
        'Guam',
        'Hawaii',
        'Idaho',
        'Illinois',
        'Indiana',
        'Iowa',
        'Kansas',
        'Kentucky',
        'Louisiana',
        'Maine',
        'Maryland',
        'Massachusetts',
        'Michigan',
        'Minnesota',
        'Mississippi',
        'Missouri',
        'Montana',
        'Nebraska',
        'Nevada',
        'New Hampshire',
        'New Jersey',
        'New Mexico',
        'New York',
        'North Carolina',
        'North Dakota',
        'Northern Mariana Islands',
        'Ohio',
        'Oklahoma',
        'Oregon',
        'Pennsylvania',
        'Puerto Rico',
        'Rhode Island',
        'South Carolina',
        'South Dakota',
        'Tennessee',
        'Texas',
        'Utah',
        'Vermont',
        'Virgin Islands',
        'Virginia',
        'Washington',
        'West Virginia',
        'Wisconsin',
        'Wyoming'
      ]
    }
  });
}());