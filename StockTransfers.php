<?php
/* $Revision: 1.8 $ */
include('includes/DefineSerialItems.php');
include('includes/DefineStockTransfer.php');

$PageSecurity = 11;
include('includes/session.inc');
$title = _('Stock Transfers');

include('includes/header.inc');
include('includes/DateFunctions.inc');
include('includes/SQL_CommonFunctions.inc');


if (isset($_GET['NewTransfer'])){
     unset($_SESSION['Transfer']);
     $_SESSION['Transfer'] = new StockTransfer;
}

if (!isset($_SESSION['Transfer'])){
     $_SESSION['Transfer'] = new StockTransfer;
}

$NewTransfer = false; /*initialise this first then determine from form inputs */

if (isset($_GET['StockID'])){
	$_SESSION['Transfer']->StockID =$_GET['StockID'];
	$NewTransfer = true;
} elseif (isset($_POST['StockID'])){
	if ($_POST['StockID'] != $_SESSION['Transfer']->StockID){
		$NewTransfer = true;
		$_SESSION['Transfer']->StockID =$_POST['StockID'];
	}
	$_SESSION['Transfer']->Narrative = $_POST['Narrative'];
	$_SESSION['Transfer']->StockLocationFrom = $_POST['FromStockLocation'];
	$_SESSION['Transfer']->StockLocationTo = $_POST['ToStockLocation'];
	if ($_POST['Quantity']=="" or !is_numeric($_POST['Quantity'])){
		$_POST['Quantity']=0;
	}
	$_SESSION['Transfer']->Quantity = $_POST['Quantity'];
}

if ($NewTransfer){

	$result = DB_query("SELECT Description,
				Units,
				MBflag,
				Materialcost+Labourcost+Overheadcost AS StandardCost,
				Controlled,
				Serialised,
				DecimalPlaces
			FROM StockMaster
			WHERE StockID='" . $_SESSION['Transfer']->StockID . "'",
			$db);
	$myrow = DB_fetch_row($result);
	if (DB_num_rows($result) == 0){
		prnMsg( _('Unable to locate Stock Code').' '.$_SESSION['Transfer']->StockID, 'error' );
	} elseif (DB_num_rows($result)>0){

		$_SESSION['Transfer']->ItemDescription = $myrow[0];
		$_SESSION['Transfer']->PartUnit = $myrow[1];
		$_SESSION['Transfer']->StandardCost = $myrow[3];
		$_SESSION['Transfer']->Controlled = $myrow[4];
		$_SESSION['Transfer']->Serialised = $myrow[5];
		$_SESSION['Transfer']->DecimalPlaces = $myrow[6];
		$_SESSION['Transfer']->SerialItems = array();
		$_SESSION['Transfer']->Quantity =0;

		if ($myrow[2]=='D' OR $myrow[2]=='A' OR $myrow[2]=='K'){
			echo '<P>'._('The part entered is either or a dummy part or an assembly/kit-set part. These parts are not physical parts and no stock holding is maintained for them. Stock Transfers are therefore not possible').'.<HR>';
			echo '<A HREF="'.$rootpath.'/StockTransfers.php?' . SID .'">'._('Enter another Transfer').'</A>';
			unset ($_SESSION['Transfer']);
			include ('includes/footer.inc');
			exit;
		}
	}
}



if ( isset($_POST['EnterTransfer']) ){

	$result = DB_query("SELECT * FROM StockMaster WHERE StockID='" . $_SESSION['Transfer']->StockID ."'",$db);
	$myrow = DB_fetch_row($result);
	$InputError = false;
	if (DB_num_rows($result)==0) {
		echo '<P>' . _('The entered item code does not exist');
		$InputError = true;
	} elseif (!is_numeric($_SESSION['Transfer']->Quantity)){
		echo '<P>'._('The quantity entered must be numeric');
		$InputError = true;
	} elseif ($_SESSION['Transfer']->Quantity<=0){
		echo '<P>'._('The quantity entered must be a positive number greater than zero');
		$InputError = true;
	} elseif ($_SESSION['Transfer']->StockLocationFrom==$_SESSION['Transfer']->StockLocationTo){
		echo '<P>'._('The locations to transfer from and to must be different');
		$InputError = true;
	}

	if ($InputError==False) {
/*All inputs must be sensible so make the stock movement records and update the locations stocks */

		$TransferNumber = GetNextTransNo(16,$db);
		$PeriodNo = GetPeriod (Date($DefaultDateFormat), $db);
		$SQLTransferDate = FormatDateForSQL(Date($DefaultDateFormat));

		$Result = DB_query('BEGIN',$db);

		// Need to get the current location quantity will need it later for the stock movement
		$SQL="SELECT LocStock.Quantity FROM LocStock WHERE LocStock.StockID='" . $_SESSION['Transfer']->StockID . "' AND LocCode= '" . $_SESSION['Transfer']->StockLocationFrom . "'";

		$ErrMsg =  _('Could not retrieve the QOH at the sending location because');
		$DbgMsg =  _('The SQL that failed was');
		$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);

		if (DB_num_rows($Result)==1){
			$LocQtyRow = DB_fetch_row($Result);
			$QtyOnHandPrior = $LocQtyRow[0];
		} else {
			// There must actually be some error this should never happen
			$QtyOnHandPrior = 0;
		}

		// Insert the stock movement for the stock going out of the from location
		$SQL = "INSERT INTO StockMoves (StockID,
						Type,
						TransNo,
						LocCode,
						TranDate,
						Prd,
						Reference,
						Qty,
						NewQOH)
			VALUES ('" .
					$_SESSION['Transfer']->StockID . "',
					16,
					" . $TransferNumber . ",
					'" . $_SESSION['Transfer']->StockLocationFrom . "',
					'" . $SQLTransferDate . "'," . $PeriodNo . ",
					'To " . $_SESSION['Transfer']->StockLocationTo ."',
					" . -$_SESSION['Transfer']->Quantity . ",
					" . ($QtyOnHandPrior - $_SESSION['Transfer']->Quantity) .
				")";

		$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The stock movement record cannot be inserted because');
		$DbgMsg =  _('The following SQL to insert the stock movement record was used');
		$Result = DB_query($SQL,$db,$ErrMsg,$DbgMsg,true);

		/*Get the ID of the StockMove... */
		$StkMoveNo = DB_Last_Insert_ID($db);

/*Insert the StockSerialMovements and update the StockSerialItems  for controlled items*/

		if ($_SESSION['Transfer']->Controlled ==1){
			foreach($_SESSION['Transfer']->SerialItems as $Item){
			/*We need to add or update the StockSerialItem record and
			The StockSerialMoves as well */

				/*First need to check if the serial items already exists or not in the location from */
				$SQL = "SELECT Count(*)
					FROM StockSerialItems
					WHERE
					StockID='" . $_SESSION['Transfer']->StockID . "'
					AND LocCode='" . $_SESSION['Transfer']->StockLocationFrom . "'
					AND SerialNo='" . $Item->BundleRef . "'";

				$ErrMsg =  _('The entered item code does not exist');
				$Result = DB_query($SQL,$db,$ErrMsg);
				$SerialItemExistsRow = DB_fetch_row($Result);

				if ($SerialItemExistsRow[0]==1){

					$SQL = "UPDATE StockSerialItems SET
						Quantity= Quantity - " . $Item->BundleQty . "
						WHERE
						StockID='" . $_SESSION['Transfer']->StockID . "'
						AND LocCode='" . $_SESSION['Transfer']->StockLocationFrom . "'
						AND SerialNo='" . $Item->BundleRef . "'";

					$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock item record could not be updated because');
					$DbgMsg = _('The following SQL to update the serial stock item record was used');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				} else {
					/*Need to insert a new serial item record */
					$SQL = "INSERT INTO StockSerialItems (StockID,
										LocCode,
										SerialNo,
										Quantity)
						VALUES ('" . $_SESSION['Transfer']->StockID . "',
						'" . $_SESSION['Transfer']->StockLocationFrom . "',
						'" . $Item->BundleRef . "',
						" . -$Item->BundleQty . ")";

					$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock item record could not be updated because');
					$DbgMsg = _('The following SQL to update the serial stock item record was used');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				}


				/* now insert the serial stock movement */

				$SQL = "INSERT INTO StockSerialMoves (StockMoveNo, StockID, SerialNo, MoveQty) VALUES (" . $StkMoveNo . ", '" . $_SESSION['Transfer']->StockID . "', '" . $Item->BundleRef . "', -" . $Item->BundleQty . ")";
				$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock movement record could not be inserted because');
				$DbgMsg = _('The following SQL to insert the serial stock movement records was used');
				$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);

			}/* foreach controlled item in the serialitems array */
		} /*end if the transferred item is a controlled item */


		// Need to get the current location quantity will need it later for the stock movement
		$SQL="SELECT LocStock.Quantity
			FROM LocStock
			WHERE LocStock.StockID='" . $_SESSION['Transfer']->StockID . "'
				AND LocCode= '" . $_SESSION['Transfer']->StockLocationFromTo . "'";
		$ErrMsg = _('Could not retrieve QOH at the destination because');
		$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg,true);
		if (DB_num_rows($Result)==1){
			$LocQtyRow = DB_fetch_row($Result);
			$QtyOnHandPrior = $LocQtyRow[0];
		} else {
			// There must actually be some error this should never happen
			$QtyOnHandPrior = 0;
		}

		// Insert the stock movement for the stock coming into the to location
		$SQL = "INSERT INTO StockMoves (StockID,
						Type,
						TransNo,
						LocCode,
						TranDate,
						Prd,
						Reference,
						Qty,
						NewQOH)
			VALUES ('" . $_SESSION['Transfer']->StockID . "',
					16,
					" . $TransferNumber . ",
					'" . $_SESSION['Transfer']->StockLocationTo . "',
					'" . $SQLTransferDate . "',
					" . $PeriodNo . ",
					'From " . $_SESSION['Transfer']->StockLocationFrom ."',
					" . $_SESSION['Transfer']->Quantity . ",
					" . ($QtyOnHandPrior + $_SESSION['Transfer']->Quantity) .
				")";

		$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The stock movement record cannot be inserted because');
		$DbgMsg = _('The following SQL to insert the stock movement record was used');
		$Result = DB_query($SQL,$db,$ErrMsg,$DbgMsg,true);

		/*Get the ID of the StockMove... */
		$StkMoveNo = DB_Last_Insert_ID($db);

/*Insert the StockSerialMovements and update the StockSerialItems  for controlled items*/

		if ($_SESSION['Transfer']->Controlled ==1){
			foreach($_SESSION['Transfer']->SerialItems as $Item){
			/*We need to add or update the StockSerialItem record and
			The StockSerialMoves as well */

				/*First need to check if the serial items already exists or not in the location from */
				$SQL = "SELECT Count(*)
					FROM StockSerialItems
					WHERE
					StockID='" . $_SESSION['Transfer']->StockID . "'
					AND LocCode='" . $_SESSION['Transfer']->StockLocationTo . "'
					AND SerialNo='" . $Item->BundleRef . "'";

				$ErrMsg = _('Could not determine if the serial item exists in the transfer to location');
				$Result = DB_query($SQL,$db,$ErrMsg);
				$SerialItemExistsRow = DB_fetch_row($Result);

				if ($SerialItemExistsRow[0]==1){

					$SQL = "UPDATE StockSerialItems SET
						Quantity= Quantity + " . $Item->BundleQty . "
						WHERE
						StockID='" . $_SESSION['Transfer']->StockID . "'
						AND LocCode='" . $_SESSION['Transfer']->StockLocationTo . "'
						AND SerialNo='" . $Item->BundleRef . "'";

					$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock item record could not be updated because');
					$DbgMsg = _('The following SQL to update the serial stock item record was used');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				} else {
					/*Need to insert a new serial item record */
					$SQL = "INSERT INTO StockSerialItems (StockID,
										LocCode,
										SerialNo,
										Quantity)
						VALUES ('" . $_SESSION['Transfer']->StockID . "',
						'" . $_SESSION['Transfer']->StockLocationTo . "',
						'" . $Item->BundleRef . "',
						" . $Item->BundleQty . ")";

					$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock item record could not be updated because');
					$DbgMsg = _('The following SQL to update the serial stock item record was used:');
					$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);
				}


				/* now insert the serial stock movement */

				$SQL = "INSERT INTO StockSerialMoves (StockMoveNo, StockID, SerialNo, MoveQty) VALUES (" . $StkMoveNo . ", '" . $_SESSION['Transfer']->StockID . "', '" . $Item->BundleRef . "', " . $Item->BundleQty . ")";
				$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The serial stock movement record could not be inserted because');
				$DbgMsg = _('The following SQL to insert the serial stock movement records was used');
				$Result = DB_query($SQL, $db, $ErrMsg, $DbgMsg, true);

			}/* foreach controlled item in the serialitems array */
		} /*end if the transfer item is a controlled item */


		$SQL = "UPDATE LocStock
			SET Quantity = Quantity - " . $_SESSION['Transfer']->Quantity . "
			WHERE StockID='" . $_SESSION['Transfer']->StockID . "'
			AND LocCode='" . $_SESSION['Transfer']->StockLocationFrom . "'";

		$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The location stock record could not be updated because');
		$DbgMsg = _('The following SQL to update the location stock record was used');
		$Result = DB_query($SQL,$db,$Errmsg,$DbgMsg,true);

		$SQL = "UPDATE LocStock
			SET Quantity = Quantity + " . $_SESSION['Transfer']->Quantity . "
			WHERE StockID='" . $_SESSION['Transfer']->StockID . "'
			AND LocCode='" . $_SESSION['Transfer']->StockLocationFromTo . "'";



		$ErrMsg = _('CRITICAL ERROR! NOTE DOWN THIS ERROR AND SEEK ASSISTANCE: The location stock record could not be updated because');
		$DbgMsg = _('The following SQL to update the location stock record was used');
		$Result = DB_query($SQL,$db,$ErrMsg, $DbgMsg, true);

		echo '<P>'. _('An inventory transfer of').' ' . $_SESSION['Transfer']->StockID . ' - ' . $_SESSION['Transfer']->PartDescription . ' '. _('has been created from').' ' . $_SESSION['Transfer']->StockLocationFrom . ' '. _('to') . ' ' . $_SESSION['Transfer']->StockLocationTo . ' '._('for a quantity of').' ' . $_SESSION['Transfer']->Quantity;
		unset ($_SESSION['Transfer']);
		include ('includes/footer.inc');
		exit;
	}

}


echo '<FORM ACTION="'. $_SERVER['PHP_SELF'] . '?' . SID . '" METHOD=POST>';


echo '<CENTER>
	<TABLE>
	<TR>
	<TD>'. _('Stock Code').':</TD>
	<TD><input type=text name="StockID" size=21 value="' . $_SESSION['Transfer']->StockID . '" maxlength=20></TD>
	<TD><INPUT TYPE=SUBMIT NAME="CheckCode" VALUE="'._('Check Part').'"></TD>
	</TR>';

if (strlen($_SESSION['Transfer']->ItemDescription)>1){
	echo '<TR><TD COLSPAN=3><FONT COLOR=BLUE SIZE=3>' . $_SESSION['Transfer']->ItemDescription . ' ('._('In Units of').' ' . $_SESSION['Transfer']->PartUnit . ' )</FONT></TD></TR>';
}

echo '<TR><TD>' .('From Stock Location').': </TD><TD><SELECT name="FromStockLocation">';

$sql = 'SELECT LocCode, LocationName FROM Locations';
$resultStkLocs = DB_query($sql,$db);
while ($myrow=DB_fetch_array($resultStkLocs)){
	if (isset($_SESSION['Transfer']->StockLocationFrom)){
		if ($myrow['LocCode'] == $_SESSION['Transfer']->StockLocationFrom){
		     echo '<OPTION SELECTED Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		} else {
		     echo '<OPTION Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		}
	} elseif ($myrow['LocCode']==$_SESSION['UserStockLocation']){
		 echo '<OPTION SELECTED Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		 $_SESSION['Transfer']->StockLocationFrom=$myrow['LocCode'];
	} else {
		 echo '<OPTION Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
	}
}

echo '</SELECT></TD></TR>';

echo '<TR><TD>'. _('To Stock Location').': </TD><TD><SELECT name="ToStockLocation"> ';

DB_data_seek($resultStkLocs,0);

while ($myrow=DB_fetch_array($resultStkLocs)){
	if (isset($_SESSION['Transfer']->StockLocationFromTo)){
		if ($myrow['LocCode'] == $_SESSION['Transfer']->StockLocationFromTo){
		     echo '<OPTION SELECTED Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		} else {
		     echo '<OPTION Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		}
	} elseif ($myrow['LocCode']==$_SESSION['UserStockLocation']){
		 echo '<OPTION SELECTED Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
		 $_SESSION['Transfer']->StockLocationFromTo=$myrow['LocCode'];
	} else {
		 echo '<OPTION Value="' . $myrow['LocCode'] . '">' . $myrow['LocationName'];
	}
}

echo '</SELECT></TD></TR>';


echo '<TR><TD>'._('Transfer Quantity').':</TD>';

if ($_SESSION['Transfer']->Controlled==1){

		echo '<TD><INPUT TYPE=HIDDEN NAME="Quantity" Value="' . $_SESSION['Transfer']->Quantity . '"><A HREF="'.$rootpath.'/StockTransferControlled.php?' . SID . '">' . $_SESSION['Transfer']->Quantity . '</A></TD></TR>';

} else {
	echo '<TD><INPUT TYPE=TEXT NAME="Quantity" SIZE=12 MAXLENGTH=12 Value="' . $_SESSION['Transfer']->Quantity . '"></TD></TR>';
}



echo '</TABLE><BR><INPUT TYPE=SUBMIT NAME="EnterTransfer" VALUE="Enter Stock Transfer">';
echo '<HR>';


echo '<A HREF="'.$rootpath.'/StockStatus.php?' . SID . 'StockID=' . $_SESSION['Transfer']->StockID . '">'._('Show Stock Status').'</A>';
echo '<BR><A HREF="'.$rootpath.'/StockMovements.php?' . SID . 'StockID=' . $_SESSION['Transfer']->StockID . '">'._('Show Movements').'</A>';
echo '<BR><A HREF="'.$rootpath.'/StockUsage.php?' . SID . 'StockID=' . $_SESSION['Transfer']->StockID . '&StockLocation=' . $_SESSION['Transfer']->StockLocationFrom . '">'._('Show Stock Usage').'</A>';
echo '<BR><A HREF="'.$rootpath.'/SelectSalesOrder.php?' . SID . 'SelectedStockItem=' . $_SESSION['Transfer']->StockID . '&StockLocation=' . $_SESSION['Transfer']->StockLocationFrom . '">'._('Search Outstanding Sales Orders').'</A>';
echo '<BR><A HREF="'.$rootpath.'/SelectCompletedOrder.php?' . SID . 'SelectedStockItem=' . $_SESSION['Transfer']->StockID . '">'._('Search Completed Sales Orders').'</A>';

echo '</form>';
include('includes/footer.inc');
?>
