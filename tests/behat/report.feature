@local @local_catquizlab
Feature: CAT experiment suite report page
  As an administrator
  I want to open the results report page
  So that I can review a run's metrics and an experiment's trends

  Scenario: The report page loads and prompts for a selection
    Given I log in as "admin"
    When I visit "/local/catquizlab/report.php"
    Then I should see "CAT experiment report"
    And I should see "Select a run or an experiment to view its report."
