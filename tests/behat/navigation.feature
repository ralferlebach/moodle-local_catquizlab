@local @local_catquizlab
Feature: Reaching the CAT experiment suite management page
  In order to prepare and run CAT experiments
  As a manager
  I need to reach the suite from the navbar and from the reports section

  Background:
    Given I log in as "admin"

  @javascript
  Scenario: The navbar button opens the management page
    When I follow "CATQUIZ-Lab"
    Then I should see "CAT experiment suite"
    And I should see "Environment"

  Scenario: The management page is listed under site administration reports
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "CAT experiment suite"
    And I should see "No experiments defined yet."
