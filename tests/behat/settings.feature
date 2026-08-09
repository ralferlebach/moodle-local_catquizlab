@local @local_catquizlab
Feature: CAT experiment suite settings page
  As an administrator
  I want to configure the instance role and enable switch of the experiment suite
  So that experiments run only where and how they are intended to

  Scenario: Administrator can open the plugin settings page
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CAT experiment suite" in site administration
    Then I should see "Environment status"
    And I should see "Enable experiment runs"
    And I should see "Instance role"

  Scenario: Experiment runs are disabled by default and the role defaults to node
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > CAT experiment suite" in site administration
    Then the field "Enable experiment runs" matches value "0"
    And the field "Instance role" matches value "Node (runs experiments)"
