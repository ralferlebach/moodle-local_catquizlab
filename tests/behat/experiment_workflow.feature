@local @local_catquizlab
Feature: Defining and running CAT experiments from the web interface
  In order to run the experimental design without the command line
  As a manager
  I need to create, validate, expand and inspect experiments in the browser

  Background:
    Given I log in as "admin"

  Scenario: The empty registry offers a way forward instead of a dead end
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "No experiments defined yet."
    And I should see "New experiment"

  Scenario: Creating an experiment through the editor
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    Then I should see "New experiment"
    And I should see "Master seed"
    When I set the following fields to these values:
      | Name                        | Behat baseline |
      | Persons per run             | 5              |
      | Minimum items (global)      | 10             |
      | Maximum items (global)      | 15             |
      | SE lower bound              | 0.35           |
      | SE upper bound              | 0.75           |
    And I press "Save experiment"
    Then I should see "Experiment saved."
    And I should see "Edit experiment"

  Scenario: A contradictory budget is refused with a field-level message
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "New experiment"
    And I set the following fields to these values:
      | Name                   | Behat broken budget |
      | Minimum items (global) | 40                  |
      | Maximum items (global) | 10                  |
    And I press "Save experiment"
    Then I should see "minitems must not exceed maxitems"

  Scenario: The sweep preview reports the runs before anything is created
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat preview |
      | replications | 2             |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat preview"
    Then I should see "Sweep preview"
    And I should see "Resulting runs"
    And I should see "Create sweep"

  Scenario: Creating a sweep from the editor produces runs
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat sweep |
      | replications | 2           |
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Behat sweep"
    And I press "Create sweep"
    Then I should see "Sweep created with"

  Scenario: The experiment overview shows publication labels
    Given the following "local_catquizlab > experiment" exists:
      | name     | Behat labelled |
      | strategy | lowestsub      |
    When I navigate to "Reports > CAT experiment suite" in site administration
    Then I should see "Detect weakest subscale"

  Scenario: Runs can be filtered and opened
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat runs |
      | replications | 2          |
    And the experiment "Behat runs" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "All runs"
    Then I should see "Runs"
    And I should see "Behat runs"
    And I should see "Any status"

  Scenario: A run detail page exposes the reproducibility manifest
    Given the following "local_catquizlab > experiment" exists:
      | name         | Behat manifest |
      | replications | 1              |
    And the experiment "Behat manifest" has been expanded into runs
    And I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "All runs"
    And I click on "1" "link" in the "Behat manifest" "table_row"
    Then I should see "Reproducibility manifest"
    And I should see "Cell key"

  Scenario: The import page previews a file before storing it
    Given I navigate to "Reports > CAT experiment suite" in site administration
    When I follow "Import settings (JSON)"
    Then I should see "Import experiment settings"
    And I should see "Definition file"
    And I should see "If the name already exists"
