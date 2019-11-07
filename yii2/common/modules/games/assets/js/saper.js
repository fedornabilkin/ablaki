;
$('.modal.saper-play').on('show.bs.modal', function (event) {
    var attr = $(event.relatedTarget);
    var data = attr.data();
    data.actions = ['start','play','double'];

    var game = new GameSaperModal();
    game.setData(data).openModal();
});

$('.modal.ablaki-play .double').hover(function () {
    $(this).find('.alert-double').toggleClass('hidden-xs-up');
});

function GameSaperModal() {
    var self = this;
    this.m = $('.modal.saper-play');

    this.setData = function (data){
        for(var key in data){
            self[key] = data[key];
        }

        return self;
    };

    this.defaultStateModal = function () {
        self.m.find('.play span').removeClass('text-danger text-success text-warning');
        self.m.find('.alert-play').html('');
        return self;
    };

    this.openModal = function (){
        this.defaultStateModal();
        for(var key in self.actions){
            self.m.find('a.'+self.actions[key]).attr('href', '/games/saper/'+self.actions[key]+'/' + self.gid);
        }
        self.m.find('a.start').removeClass('hidden');

        $(self.m).find('.modal-header .gid').html(self.gid);
        $(self.m).find('.kon').html(self.kon);
        $(self.m).find('.kon-double').html(self.kon * 2);
        return self;
    };

    this.start = function (){};
    this.play = function () {};
    this.double = function () {};

};

function SaperStart(element, data) {
    AjaxResponse.apply(this, arguments);
    // this.gameMonitoring = new gameMonitoring();
}
SaperStart.prototype = Object.create(AjaxResponse.prototype);
SaperStart.prototype.constructor = SaperStart;
SaperStart.prototype.after = function(){
    AjaxResponse.prototype.after.apply(this);
    // this.userbar.setData({balance: 0 - this.resp.row.kon});
    // this.gameMonitoring.setData({profit:0 - this.resp.row.kon, win:0, count:0});
    this.element.addClass('hidden');
};


function SaperPlay(element, data) {
    AjaxResponse.apply(this, arguments);
    // this.gameMonitoring = new gameMonitoring();
};
SaperPlay.prototype = Object.create(AjaxResponse.prototype);
SaperPlay.prototype.constructor = SaperPlay;
SaperPlay.prototype.after = function(){
    var apple = 'text-warning';
    var set = {profit:0, win:0, count:0};

    if(this.resp.hod === true){apple = 'text-success';}
    if(this.resp.hod === false){apple = 'text-danger';}
    this.element.find('span').addClass(apple);

    // if (this.resp.row.etap >= 3 && this.resp.row.kon >= 0.01 && this.resp.hod === true) {
    //     this.element.parents('.modal').find('.double').removeClass('hidden-xs-up');
    // } else {
    //     this.element.parents('.modal').find('.double').addClass('hidden-xs-up');
    // }
    // if(this.resp.row.etap == 1 || this.resp.hod === false){
        // $('tr[data-id="'+this.resp.row.id+'"]').fadeOut(600);
        // this.userbar.setData({balance:this.resp.row.profit});
        // set.profit = this.resp.row.profit;
        // set.count = 1;
        // if(this.resp.hod !== false){
        //     set.win = 1;
        // }
    // }
    // this.gameMonitoring.setData(set);
};


function AblakiDouble(element, data) {
    AjaxResponse.apply(this, arguments);
    // this.gameMonitoring = new gameMonitoring();
};
AblakiDouble.prototype = Object.create(AjaxResponse.prototype);
AblakiDouble.prototype.constructor = AblakiDouble;
AblakiDouble.prototype.after = function(){
    this.element.parents('.modal').find('.kon').html(this.resp.row.kon * 2);
    this.userbar.setData({balance: 0 - this.resp.row.kon * 1});
    this.gameMonitoring.setData({profit:0 - this.resp.row.kon, win:0, count:0});
};