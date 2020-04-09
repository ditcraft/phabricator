<?php

final class PhabricatorBritishEnglishTranslation
  extends PhutilTranslation {

  public function getLocaleCode() {
    return 'en_GB';
  }

  protected function getTranslations() {
    return array(
      "%s set this project's color to %s." =>
        "%s set this project's colour to %s.",
      'Basic Colors' =>
        'Basic Colours',
      'Choose Icon and Color...' =>
        'Choose Icon and Colour...',
      'Choose Background Color' =>
        'Choose Background Colour',
      'Color' => 'Colour',
      'Colors' => 'Colours',
      'Colors and Transforms' => 'Colours and Transforms',
      'Configure the ditCraft UI, including colors.' =>
        'Configure the ditCraft UI, including colours.',
      'Flag Color' => 'Flag Colour',
      'Sets the color of the main header.' =>
        'Sets the colour of the main header.',
    );
  }

}
