;$(document).ready(function (){

    $('a[data-request="ajax"]').on('click', function () {
        AjaxRequest($(this));
        return false;
    });

    $('span[data-request="ajax"]').on('click', function () {
        AjaxRequest($(this));
    });

    $('form[data-request="ajax"]').on('beforeSubmit', function () {
        AjaxRequest($(this));
        return false;
    });

}); // $(document).ready

function ModelTest(element, data) {
    AjaxResponse.apply(this, arguments);
}
ModelTest.prototype = Object.create(AjaxResponse.prototype);
ModelTest.prototype.constructor = ModelTest;
ModelTest.prototype.before = function() {
    var status = AjaxResponse.prototype.before.apply(this);
    // put code validate
    return status;
};
ModelTest.prototype.after = function(){
    AjaxResponse.prototype.after.apply(this);
    console.log(this.resp.model);
};