<?php

final class PhabricatorPDFInfoObject
  extends PhabricatorPDFObject {

  final protected function writeObject() {
    $this->writeLine('/Producer (ditCraft 20190801)');
    $this->writeLine('/CreationDate (D:%s)', date('YmdHis'));
  }

}
