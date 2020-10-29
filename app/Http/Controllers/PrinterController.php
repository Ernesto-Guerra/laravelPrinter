<?php

namespace App\Http\Controllers;

use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\Printer;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    public function reprint(Request $request)
    {                
        try {
            //return $request;            
            $sale = json_encode($request->data);
            $sale = json_decode($sale);                        
                            
            $tipoPago = $sale->payment_type == "1" ? 'Pago en efectivo' : 'Pago con tarjeta';        
                    
            //dd($sale);
            $nombreImpresora = 'ImpresoraTermica';            
            $connector = new WindowsPrintConnector($nombreImpresora);         
                    
            $printer = new Printer($connector);
            
            /* Initialize */
            $printer -> initialize();

            /* Information for the receipt */
            $items = array();

            $services = array();
                                    
            foreach ($sale->products_in_sale as $product) {              
                if($product->product->category->name == 'Servicio'){
                    array_push($services,
                    new service($product->quantity,$product->product->name)
                );
                }          

                if(strlen($product->product->name) > 15){
                    array_push($items,
                        new fitem($product->quantity.'x '.substr($product->product->name,0,15),'$'.$product->sale_price,$product->discount.'%','$'.$product->subtotal)
                        );
                        $product->product->name = substr($product->product->name,15);
                        $iter = 0;
                    while (strlen($product->product->name) > 17) {
                        array_push($items,
                            new fitem(substr($product->product->name,($iter * 17),($iter*17)+17),'','','')
                        );
                        $product->product->name = substr($product->product->name,($iter*17)+17);
                    }
                    array_push($items,
                        new fitem($product->product->name,'','','')
                    );
                }
                else{
                    array_push($items,
                        new fitem($product->quantity.'x '.$product->product->name,'$'.$product->sale_price,$product->discount.'%','$'.$product->subtotal)
                    );
                }
                
            }
            
            $subtotal = new item('Subtotal', '$'.$sale->cart_subtotal);
            $percent = new item('Descuento general', $sale->discount.'%');
            $tax = new item('Descuento monetario', '$'.$sale->amount_discount);
            $total = new item('Total', '$'.$sale->cart_total);
            /* Date is kept the same for testing */
            date_default_timezone_set('America/Mexico_City');
            setlocale(LC_TIME, 'es_ES.UTF-8');
            $date = date('d-m-Y h:i:s A');
            //$date = "Monday 6th of April 2015 02:56:25 PM";

            // /* Start the printer */        
            $logo = EscposImage::load("logo.png", false);      

            /* Print top logo */
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> graphics($logo,Printer::IMG_DOUBLE_WIDTH);
            $printer -> setJustification();
            $printer->feed(1);
            
            /* Name of shop */
            $printer -> selectPrintMode(Printer::MODE_DOUBLE_WIDTH);
            $printer -> text("RUEDA BICENTENARIA SAPI DE CV\n");        
            $printer -> selectPrintMode();
            $printer -> text("Sucursal: ".$sale->branch_office->name."\n");  
            $printer -> text("Vendedor: ".$sale->user->name."\n");                              
            $printer -> feed();

            /* Title of receipt */
            $printer -> setEmphasis(true);
            $printer -> text("Folio: ".$sale->folio_branch_office."\n");        
            $printer -> setEmphasis(false);

            /* Items */
            $printer -> setJustification(Printer::JUSTIFY_LEFT);
            $printer -> setEmphasis(true);
            $printer -> text(new fitem('Productos', 'Precio', 'Descuento', 'Importe'));        
            $printer -> setEmphasis(false);
            foreach ($items as $item) {
                $printer -> text($item);
            }
            $printer -> setEmphasis(true);
            $printer -> text($subtotal);       
            $printer -> text(new item('Paga con','$'.$sale->ingress));
            $printer -> text(new item('Cambio','$'.$sale->turned)); 
            $printer -> setEmphasis(false);
            $printer -> feed();

            /* Tax and total */

            if($sale->discount != null && $sale->discount != 0 && $sale->discount != "0"){
                $printer -> text($percent);     
            }
            if($sale->amount_discount != null && $sale->amount_discount != 0 && $sale->amount_discount != "0"){
                $printer -> text($tax);    
            }        
            $printer -> selectPrintMode(Printer::MODE_DOUBLE_WIDTH);            
            $printer -> text($total);        
            $printer -> text($tipoPago."\n");
            $printer -> selectPrintMode();

            /* Footer */
            $printer -> feed(2);
            $printer -> setJustification(Printer::JUSTIFY_CENTER);
            $printer -> text("Gracias por visitar la gran rueda\n");        
            $printer -> text("Para mas información, visita elsoldecancun.com\n");        
            $printer -> feed(2);
            $printer -> text($date . "\n");        
            $printer -> setJustification();

            /* Cut the receipt and open the cash drawer */
            $printer -> cut();

            // imprime todos los servicios con folio
            foreach($services as $service){
                for($i = 0; $i<$service->quantity;$i++){
                    $printer -> setJustification(Printer::JUSTIFY_CENTER);
                    $printer->text('Folio: '.$sale->folio_branch_office.'      Sucursal: '.$sale->branch_office->name."\n");                            
                    $printer->text($service->name."\n");                                               
                    $printer->text($date);                
                    $printer->feed();
                    $printer->cut();
                }
            }

            $printer -> pulse();

            $printer -> close();     
        
            return response()->json(['success' => true]);
        } catch (\Throwable $th) {
            return $th;
            return response()->json(['success' => false]);
        }    
    }
}
class item
{
    private $name;
    private $price;
    private $dollarSign;

    public function __construct($name = '', $price = '', $dollarSign = false)
    {
        $this -> name = $name;
        $this -> price = $price;
        $this -> dollarSign = $dollarSign;
    }
    
    public function __toString()
    {
        $rightCols = 10;
        $leftCols = 38;
        if ($this -> dollarSign) {
            $leftCols = $leftCols / 2 - $rightCols / 2;
        }
        $left = str_pad($this -> name, $leftCols) ;
        
        $sign = ($this -> dollarSign ? '$ ' : '');
        $right = str_pad($sign . $this -> price, $rightCols, ' ', STR_PAD_LEFT);
        return "$left$right\n";
    }
}

class fitem
{
    private $name;
    private $price;
    private $discount;
    private $import;    

    public function __construct($name = '', $price = '', $discount = '', $import = '')
    {
        $this -> name = $name;
        $this -> price = $price;
        $this -> discount = $discount;
        $this -> import = $import;
    }
    
    public function __toString()
    {
        // total = 48
        $nameCols = 20;
        $priceCols = 9;
        $discountCols = 9;
        $importCols = 10;

        $validator = true;
        $iter = 0;
        $tempname = $this->name;

        

        $name = str_pad($this -> name, $nameCols) ;
        $price = str_pad($this -> price, $priceCols) ;
        $discount = str_pad($this -> discount, $discountCols) ;
        $import = str_pad($this -> import, $importCols,' ',STR_PAD_LEFT) ;
                
        return "$name$price$discount$import\n";
    }
}
class service
{
    public $quantity;
    public $name;   

    public function __construct($quantity = '',$name = '')
    {
        $this -> name = $name;
        $this -> quantity = $quantity;
    }
    

}