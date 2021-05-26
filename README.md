# APdf
Laravel Package for export page as PDF with support of UTF-8 like farsi character

## Usage:
1.Run this comman:

 ```  
 composer require vatttan/apdf
 ```
Now its available in every where you want like Views,Controllers,....
For example, in view you can use:
 ```  
use Illuminate\Support\Facades\Route;
use Vatttan\Apdf\Apdf;
Route::get('/', function () {
    $apdf = new Apdf();
    $apdf->print('<p style="text-align: right">Birinci gün</p>');
});
 ```  

