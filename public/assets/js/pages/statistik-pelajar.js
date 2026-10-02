document.addEventListener('DOMContentLoaded',function(){const d=window.studentStats;if(!d||!window.ApexCharts)return;const base={chart:{toolbar:{show:false},fontFamily:'inherit'},dataLabels:{enabled:true,style:{fontSize:'10px'}},grid:{borderColor:'#edf2f7'},tooltip:{y:{formatter:v=>Number(v).toLocaleString('ms-MY')+' pelajar'}}};const gradient=(from,to,type='vertical')=>({type:'gradient',gradient:{shade:'light',type,shadeIntensity:.18,gradientToColors:[to],inverseColors:false,opacityFrom:1,opacityTo:1,stops:[0,100]}});const vertical=(el,data,from,to)=>new ApexCharts(document.querySelector(el),{...base,colors:[from],fill:gradient(from,to),series:[{name:'Pelajar Aktif',data:data.values}],chart:{...base.chart,type:'bar',height:310},plotOptions:{bar:{borderRadius:5,columnWidth:'58%'}},xaxis:{categories:data.labels,labels:{rotate:-35}},yaxis:{title:{text:'Jumlah Pelajar'},labels:{formatter:v=>Number(v).toLocaleString('ms-MY')}}}).render();vertical('#facultyChart',d.faculty,'#2563eb','#60a5fa');vertical('#semesterChart',d.semester,'#059669','#34d399');new ApexCharts(document.querySelector('#programChart'),{...base,colors:['#c4b5fd'],fill:gradient('#c4b5fd','#5b21b6','horizontal'),series:[{name:'Pelajar Aktif',data:d.program.values}],chart:{...base.chart,type:'bar',height:Math.max(500,d.program.labels.length*25)},plotOptions:{bar:{horizontal:true,borderRadius:4,barHeight:'56%'}},xaxis:{categories:d.program.labels,title:{text:'Jumlah Pelajar'}},yaxis:{labels:{style:{fontSize:'10px'}}}}).render();});

document.addEventListener('DOMContentLoaded', function () {
    const data = window.studentStats && window.studentStats.status;
    const target = document.querySelector('#statusChart');
    if (!data || !target || !window.ApexCharts) return;

    new ApexCharts(target, {
        chart: {
            type: 'bar',
            height: Math.max(310, data.labels.length * 38),
            toolbar: { show: false },
            fontFamily: 'inherit'
        },
        series: [{ name: 'Pelajar', data: data.values }],
        colors: ['#0891b2'],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
        dataLabels: {
            enabled: true,
            formatter: value => Number(value).toLocaleString('ms-MY')
        },
        xaxis: {
            categories: data.labels,
            title: { text: 'Jumlah Pelajar' },
            labels: { formatter: value => Number(value).toLocaleString('ms-MY') }
        },
        grid: { borderColor: '#edf2f7' },
        tooltip: { y: { formatter: value => Number(value).toLocaleString('ms-MY') + ' pelajar' } },
        noData: { text: 'Tiada rekod pelajar untuk dipaparkan.' }
    }).render();
});
